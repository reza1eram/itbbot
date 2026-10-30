<?php

namespace App\Modules\AnonymousExam\Services;

use App\Modules\AnonymousExam\Bale\BaleApi;
use App\Modules\AnonymousExam\Bale\BaleApiException;
use App\Modules\AnonymousExam\Models\Exam;
use App\Modules\AnonymousExam\Models\OutboxMessage;
use Illuminate\Support\Facades\Log;

/**
 * Queue of outgoing GROUP messages with retry/backoff and rate limiting.
 * Private messages carrying access links are intentionally NOT routed through here (nothing about
 * individual participants may be persisted).
 */
class OutboxService
{
    public const MAX_ATTEMPTS = 6;
    public const MAX_TEXT = 4096;

    public function __construct(private readonly BaleApi $bale, private readonly int $perSecond = 20)
    {
    }

    public function enqueue(int $chatId, string $text, ?array $replyMarkup = null, ?int $examId = null, ?string $purpose = null, bool $sendNow = true): OutboxMessage
    {
        $message = OutboxMessage::query()->create([
            'chat_id' => $chatId,
            'exam_id' => $examId,
            'purpose' => $purpose,
            'text' => $text,
            'reply_markup' => $replyMarkup,
            'status' => 'pending',
            'next_attempt_at' => now(),
        ]);

        if ($sendNow) {
            $this->deliver($message);
        }

        return $message->refresh();
    }

    /** Sends everything that is due. Returns number of messages sent. */
    public function dispatchDue(int $limit = 50): int
    {
        $sent = 0;
        $due = OutboxMessage::query()
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($due as $message) {
            if ($this->deliver($message)) {
                $sent++;
            }
            if (!app()->runningUnitTests()) {
                usleep((int) (1_000_000 / max(1, $this->perSecond)));
            }
        }

        return $sent;
    }

    public function deliver(OutboxMessage $message): bool
    {
        // Atomic claim so that two workers never send the same row
        $claimed = OutboxMessage::query()
            ->whereKey($message->id)
            ->where('status', 'pending')
            ->update(['status' => 'sending', 'attempts' => $message->attempts + 1, 'updated_at' => now()]);
        if (!$claimed) {
            return false;
        }
        $message->refresh();

        try {
            $chunks = self::chunk($message->text);
            $lastId = null;
            foreach ($chunks as $i => $chunk) {
                $markup = $i === array_key_last($chunks) ? $message->reply_markup : null;
                $result = $this->bale->sendMessage($message->chat_id, $chunk, $markup ?: null);
                $lastId = $result['message_id'] ?? $lastId;
            }

            $message->forceFill(['status' => 'sent', 'sent_message_id' => $lastId, 'last_error' => null])->save();
            $this->afterSent($message);

            return true;
        } catch (BaleApiException $e) {
            $final = $message->attempts >= self::MAX_ATTEMPTS || in_array($e->errorCode, [400, 403], true);
            $delay = $e->retryAfter ?? min(60 * (2 ** ($message->attempts - 1)), 3600);
            $message->forceFill([
                'status' => $final ? 'failed' : 'pending',
                'next_attempt_at' => now()->addSeconds($delay),
                'last_error' => mb_substr(($e->errorCode ? $e->errorCode . ': ' : '') . $e->getMessage(), 0, 500),
            ])->save();
            Log::warning('ax outbox send failed', ['outbox_id' => $message->id, 'error_code' => $e->errorCode]);

            return false;
        }
    }

    public function retry(OutboxMessage $message): void
    {
        $message->forceFill(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now()])->save();
        $this->deliver($message);
    }

    /** Split long texts on line boundaries so each part is <= 4096 characters */
    public static function chunk(string $text, int $max = self::MAX_TEXT): array
    {
        if (mb_strlen($text) <= $max) {
            return [$text];
        }
        $parts = [];
        $current = '';
        foreach (preg_split("/(\n)/u", $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
            while (mb_strlen($piece) > $max) {
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }
                $parts[] = mb_substr($piece, 0, $max);
                $piece = mb_substr($piece, $max);
            }
            if (mb_strlen($current . $piece) > $max) {
                $parts[] = $current;
                $current = '';
            }
            $current .= $piece;
        }
        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return array_values(array_filter($parts, fn ($p) => trim($p) !== ''));
    }

    private function afterSent(OutboxMessage $message): void
    {
        if ($message->purpose === 'announce' && $message->exam_id && $message->sent_message_id) {
            Exam::query()->whereKey($message->exam_id)->update(['announce_message_id' => $message->sent_message_id]);
        }
    }
}
