<?php

namespace App\Modules\AnonymousExam\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\AnonymousExam\Bale\BaleApi;
use App\Modules\AnonymousExam\Models\Group;
use App\Modules\AnonymousExam\Models\OutboxMessage;
use App\Modules\AnonymousExam\Services\AuditService;
use App\Modules\AnonymousExam\Services\BotStatusService;
use App\Modules\AnonymousExam\Services\GroupService;
use App\Modules\AnonymousExam\Services\OutboxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class BotController extends Controller
{
    public function __construct(private readonly BotStatusService $status, private readonly AuditService $audit)
    {
    }

    public function index(BaleApi $bale): View
    {
        $webhook = null;
        $webhookError = null;
        if ($bale->isConfigured()) {
            try {
                $webhook = $this->status->webhookInfo();
            } catch (Throwable $e) {
                $webhookError = $e->getMessage();
            }
        }

        return view('ax::admin.bot', [
            'bot' => $this->status->cachedStatus(),
            'record' => $this->status->record(),
            'mode' => config('anonymous_exam.bale.update_mode'),
            'membershipMode' => config('anonymous_exam.bale.membership_mode'),
            'joinButton' => config('anonymous_exam.bale.join_button'),
            'webhook' => $webhook,
            'webhookError' => $webhookError,
            'groups' => Group::query()->where('is_active', true)->orderBy('title')->get(),
            'outbox' => OutboxMessage::query()->latest('id')->limit(15)->get(),
        ]);
    }

    public function check(): RedirectResponse
    {
        $result = $this->status->check();

        return back()->with($result['ok'] ? 'success' : 'error', $result['ok'] ? '@' . $result['username'] . ' در دسترس است.' : 'خطا: ' . $result['error']);
    }

    public function setWebhook(Request $request): RedirectResponse
    {
        try {
            $this->status->setWebhook();
            $this->audit->log($request->user('ax_admin'), 'bot.webhook_set');

            return back()->with('success', 'وب‌هوک تنظیم شد. توجه: در حالت webhook فرمان exam:poll نباید اجرا شود.');
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deleteWebhook(Request $request, BaleApi $bale): RedirectResponse
    {
        try {
            $bale->deleteWebhook();
            $this->audit->log($request->user('ax_admin'), 'bot.webhook_deleted');

            return back()->with('success', 'وب‌هوک حذف شد.');
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function testMessage(Request $request, GroupService $groups): RedirectResponse
    {
        $group = Group::query()->findOrFail((int) $request->input('group_id'));
        try {
            $groups->sendTestMessage($group);
            $this->audit->log($request->user('ax_admin'), 'bot.test_message', $group);

            return back()->with('success', 'پیام آزمایشی ارسال شد.');
        } catch (Throwable $e) {
            return back()->with('error', 'ارسال نشد: ' . $e->getMessage());
        }
    }

    public function retryOutbox(OutboxMessage $message, OutboxService $outbox): RedirectResponse
    {
        $outbox->retry($message);

        return back()->with($message->refresh()->status === 'sent' ? 'success' : 'error', 'وضعیت پیام: ' . $message->status);
    }
}
