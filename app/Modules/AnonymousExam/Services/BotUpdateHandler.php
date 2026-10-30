<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Bale\BaleApi;
use App\Modules\AnonymousExam\Bale\BaleApiException;
use App\Modules\AnonymousExam\Models\AdminUser;
use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\Group;
use App\Modules\AnonymousExam\Support\AccessResult;
use App\Modules\AnonymousExam\Support\Display;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handles one Bale update (message / edited_message / callback_query), from polling or webhook.
 *
 * Privacy: user ids exist here only in memory. Replies that contain access links are sent directly
 * (never via the outbox), and nothing in this class logs a user or chat id.
 */
class BotUpdateHandler
{
    private const GROUP_TYPES = ['group', 'supergroup'];

    public function __construct(
        private readonly BaleApi $bale,
        private readonly AccessService $access,
        private readonly MembershipVerifier $membership,
        private readonly TemplateService $templates,
        private readonly GroupService $groups,
        private readonly ExamLifecycleService $lifecycle,
        private readonly ResultsService $results,
        private readonly SettingsService $settings,
    ) {
    }

    public function handle(array $update): void
    {
        $updateId = $update['update_id'] ?? null;
        if ($updateId !== null && !$this->markProcessed((int) $updateId)) {
            return; // duplicate delivery
        }

        try {
            if (isset($update['callback_query'])) {
                $this->onCallback($update['callback_query']);
            } elseif (isset($update['message'])) {
                $this->onMessage($update['message']);
            }
            // edited_message: intentionally ignored (commands are not re-executed)
        } catch (Throwable $e) {
            Log::error('ax update handling failed', ['update_id' => $updateId, 'error' => class_basename($e), 'code' => $e->getCode()]);
        }
    }

    private function markProcessed(int $updateId): bool
    {
        return DB::table('ax_processed_updates')->insertOrIgnore(['update_id' => $updateId, 'day' => now()->toDateString()]) === 1;
    }

    private function onMessage(array $message): void
    {
        $text = trim((string) ($message['text'] ?? ''));
        $chat = $message['chat'] ?? [];
        $chatId = $chat['id'] ?? null;
        $userId = $message['from']['id'] ?? null;
        if ($chatId === null || $userId === null || $text === '' || $text[0] !== '/') {
            return; // the bot never reacts to ordinary chat messages
        }

        [$command, $args] = $this->parseCommand($text);
        if ($command === null) {
            return;
        }

        if (in_array($chat['type'] ?? '', self::GROUP_TYPES, true)) {
            $this->onGroupCommand($command, $args, (int) $chatId, $chat['title'] ?? null);

            return;
        }

        if (($chat['type'] ?? 'private') === 'private') {
            $this->onPrivateCommand($command, $args, $chatId, $userId);
        }
    }

    /** "/start@ITBbot exam_abc" -> ['start', 'exam_abc']; commands addressed to another bot -> null */
    private function parseCommand(string $text): array
    {
        if (!preg_match('/^\/([A-Za-z_]+)(?:@([A-Za-z0-9_]+))?(?:\s+(.*))?$/su', $text, $m)) {
            return [null, ''];
        }
        $target = $m[2] ?? '';
        if ($target !== '' && strcasecmp($target, (string) config('anonymous_exam.bale.username')) !== 0) {
            return [null, ''];
        }

        return [strtolower($m[1]), trim($m[3] ?? '')];
    }

    private function onGroupCommand(string $command, string $args, int $chatId, ?string $title): void
    {
        switch ($command) {
            case 'register':
                if ($args === '') {
                    $this->reply($chatId, $this->templates->render('register_usage'));

                    return;
                }
                $group = $this->groups->registerWithCode($chatId, $title, $args);
                $this->reply($chatId, $group
                    ? $this->templates->render('register_ok', ['title' => $group->title ?? ''])
                    : $this->templates->render('register_invalid'));

                return;
            case 'help':
                $this->reply($chatId, $this->templates->render('help_group'));

                return;
        }
        // other commands in groups: stay silent, no noise
    }

    private function onPrivateCommand(string $command, string $args, int|string $chatId, int|string $userId): void
    {
        switch ($command) {
            case 'start':
                if (preg_match('/^exam_([a-z0-9]{4,16})$/', $args, $m)) {
                    $this->join($m[1], $userId, $chatId);
                } else {
                    $this->reply($chatId, $this->templates->render('start_welcome'));
                }

                return;
            case 'help':
                $this->reply($chatId, $this->templates->render('help_private'));

                return;
            case 'privacy':
                $this->reply($chatId, $this->settings->privacyText());

                return;
            case 'exams':
                $this->listOpenExams($chatId, $userId);

                return;
            case 'status':
                $this->status($args, $chatId, $userId);

                return;
            case 'myexams':
            case 'close':
            case 'results':
                $this->adminCommand($command, $args, $chatId, $userId);

                return;
            case 'register':
                $this->reply($chatId, $this->templates->render('register_group_only'));

                return;
        }
        $this->reply($chatId, $this->templates->render('unknown_command'));
    }

    /** Membership check + one-time link, delivered in the private chat */
    private function join(string $code, int|string $userId, int|string $privateChatId): bool
    {
        $exam = Exam::query()->where('public_code', $code)->first();
        if (!$exam || $exam->status === Exam::STATUS_DRAFT) {
            $this->reply($privateChatId, $this->templates->render('exam_not_found'));

            return false;
        }

        $result = $this->access->requestAccess($exam, $userId);

        if ($result->isOk()) {
            return $this->sendAccessLink($privateChatId, $exam, (string) $result->url);
        }

        $this->reply($privateChatId, $this->failureText($exam, $result->status));

        return false;
    }

    private function sendAccessLink(int|string $chatId, Exam $exam, string $url): bool
    {
        $text = $this->templates->render('access_link', [
            'title' => $exam->title,
            'minutes' => Display::digits($this->settings->tokenTtlMinutes()),
        ]);
        $keyboard = ['inline_keyboard' => [[['text' => $this->templates->render('access_button'), 'url' => $url]]]];

        try {
            $this->bale->sendMessage($chatId, $text, $keyboard);

            return true;
        } catch (BaleApiException $e) {
            if ($e->errorCode !== 400) {
                throw $e;
            }
        }
        // Bale may reject url buttons for non-https / local addresses: fall back to a plain link
        $this->bale->sendMessage($chatId, $text . "\n\n" . $url);

        return true;
    }

    private function failureText(Exam $exam, string $status): string
    {
        return match ($status) {
            AccessResult::NOT_STARTED => $this->templates->render('exam_not_started', ['starts_at' => Display::dateTime($exam->starts_at)]),
            AccessResult::ENDED => $this->templates->render('exam_ended'),
            AccessResult::NOT_MEMBER => $this->templates->render('not_member', ['group' => $exam->group?->title ?? '']),
            AccessResult::CALLBACK_REQUIRED => $this->templates->render('callback_required'),
            AccessResult::ALREADY_SUBMITTED => $this->templates->render('already_submitted'),
            AccessResult::UNKNOWN_MEMBERSHIP => $this->templates->render('membership_unverifiable'),
            default => $this->templates->render('exam_not_found'),
        };
    }

    private function onCallback(array $callback): void
    {
        $data = (string) ($callback['data'] ?? '');
        $callbackId = (string) ($callback['id'] ?? '');
        $userId = $callback['from']['id'] ?? null;
        $chatId = $callback['message']['chat']['id'] ?? null;

        if (!preg_match('/^ax:join:([a-z0-9]{4,16})$/', $data, $m) || $userId === null) {
            $this->answerCallback($callbackId);

            return;
        }

        $exam = Exam::query()->where('public_code', $m[1])->first();
        $group = $exam?->group;

        // The tap itself is the membership proof: it came from inside THIS exam's group chat
        if ($exam && $group && $chatId !== null && (int) $chatId === (int) $group->chat_id) {
            $this->membership->rememberCallbackProof($userId, $exam);
        } else {
            $this->answerCallback($callbackId, $this->templates->render('exam_not_found'));

            return;
        }

        $result = $this->access->requestAccess($exam, $userId);
        if (!$result->isOk()) {
            $this->answerCallback($callbackId, $this->failureText($exam, $result->status), true);

            return;
        }

        try {
            // A bot can message a user privately only after the user has started it
            $this->sendAccessLink($userId, $exam, (string) $result->url);
            $this->answerCallback($callbackId, $this->templates->render('callback_sent_private'));
        } catch (BaleApiException) {
            $deepLink = 'https://ble.ir/' . config('anonymous_exam.bale.username') . '?start=exam_' . $exam->public_code;
            $this->answerCallback($callbackId, $this->templates->render('callback_start_bot_first'), true, $deepLink);
        }
    }

    private function listOpenExams(int|string $chatId, int|string $userId): void
    {
        $lines = [];
        $exams = Exam::query()->open()->with('group')->orderBy('ends_at')->limit(30)->get();
        foreach ($exams as $exam) {
            if (!$exam->group || $this->membership->verify($exam->group, $userId, $exam) !== MembershipVerifier::MEMBER) {
                continue;
            }
            $lines[] = $this->templates->render('exams_item', [
                'title' => $exam->title,
                'ends_at' => Display::dateTime($exam->ends_at),
                'code' => $exam->public_code,
            ]);
        }

        $this->reply($chatId, $lines
            ? $this->templates->render('exams_header') . "\n\n" . implode("\n\n", $lines)
            : $this->templates->render('exams_none'));
    }

    private function status(string $code, int|string $chatId, int|string $userId): void
    {
        $code = strtolower(preg_replace('/^exam_/', '', trim($code)));
        if ($code === '') {
            $this->reply($chatId, $this->templates->render('status_usage'));

            return;
        }
        $exam = Exam::query()->where('public_code', $code)->first();
        if (!$exam) {
            $this->reply($chatId, $this->templates->render('exam_not_found'));

            return;
        }
        $this->reply($chatId, $this->templates->render($this->access->hasSubmitted($exam, $userId) ? 'status_yes' : 'status_no'));
    }

    /** Optional admin commands for panel admins who registered their Bale chat id */
    private function adminCommand(string $command, string $args, int|string $chatId, int|string $userId): void
    {
        $admin = AdminUser::query()->where('bale_admin_chat_id', $userId)->where('is_active', true)->first();
        if (!$admin) {
            $this->reply($chatId, $this->templates->render('admin_only'));

            return;
        }

        if ($command === 'myexams') {
            $exams = Exam::query()->whereIn('status', [Exam::STATUS_SCHEDULED, Exam::STATUS_OPEN])->orderBy('ends_at')->limit(20)->get();
            $lines = $exams->map(fn (Exam $e) => $this->templates->render('admin_myexams_item', [
                'title' => $e->title,
                'status' => $e->status,
                'code' => $e->public_code,
                'count' => Display::digits($this->results->counts($e)['submitted']),
            ]))->all();
            $this->reply($chatId, $lines ? implode("\n", $lines) : $this->templates->render('admin_myexams_none'));

            return;
        }

        $exam = $args !== '' ? Exam::query()->where('public_code', strtolower($args))->first() : null;
        if (!$exam) {
            $this->reply($chatId, $args === '' ? $this->templates->render('admin_close_usage') : $this->templates->render('exam_not_found'));

            return;
        }

        if ($command === 'close') {
            if (!$admin->canEdit()) {
                $this->reply($chatId, $this->templates->render('admin_only'));

                return;
            }
            $this->lifecycle->close($exam, $admin);
            $this->reply($chatId, $this->templates->render('admin_close_ok', ['title' => $exam->title]));

            return;
        }

        // results
        $summary = $this->results->summary($exam);
        if (!$summary['visibility']['visible']) {
            $this->reply($chatId, $this->templates->render('admin_results_locked'));

            return;
        }
        $details = '';
        if (!empty($summary['scores']['count'])) {
            $details = 'میانگین: ' . Display::number($summary['scores']['avg'], 2) . ' — میانه: ' . Display::number($summary['scores']['median'], 2);
        }
        $this->reply($chatId, $this->templates->render('admin_results', [
            'title' => $exam->title,
            'count' => Display::digits($summary['counts']['submitted']),
            'details' => $details,
        ]));
    }

    private function reply(int|string $chatId, string $text): void
    {
        foreach (OutboxService::chunk($text) as $chunk) {
            $this->bale->sendMessage($chatId, $chunk);
        }
    }

    private function answerCallback(string $id, ?string $text = null, bool $alert = false, ?string $url = null): void
    {
        if ($id === '') {
            return;
        }
        $text = $text ? mb_substr($text, 0, 200) : null;
        try {
            $this->bale->answerCallbackQuery($id, $text, $alert, $url);
        } catch (BaleApiException) {
            // answerCallbackQuery (and its url parameter) is not guaranteed on Bale (see docs/BALE-API-VERIFIED.md)
            if ($url !== null) {
                try {
                    $this->bale->answerCallbackQuery($id, $text, $alert);
                } catch (BaleApiException) {
                }
            }
        }
    }
}
