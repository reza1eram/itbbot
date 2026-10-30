<?php

namespace App\Modules\AnonymousExam\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\AnonymousExam\Models\Group;
use App\Modules\AnonymousExam\Services\AuditService;
use App\Modules\AnonymousExam\Services\GroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class GroupController extends Controller
{
    public function __construct(private readonly GroupService $groups, private readonly AuditService $audit)
    {
    }

    public function index(): View
    {
        return view('ax::admin.groups', [
            'groups' => Group::query()->withCount(['exams', 'members as hashed_members_count'])->orderByDesc('is_active')->orderBy('title')->get(),
            'membershipMode' => config('anonymous_exam.bale.membership_mode'),
            'botUsername' => config('anonymous_exam.bale.username'),
        ]);
    }

    public function code(Request $request): RedirectResponse
    {
        $code = $this->groups->createRegistrationCode($request->user('ax_admin'));

        return back()->with('registration_code', $code['code'])->with('success', 'کد ثبت ساخته شد (۱۰ دقیقه اعتبار، یک‌بارمصرف).');
    }

    public function manual(Request $request): RedirectResponse
    {
        $data = $request->validate(['chat_id' => ['required', 'regex:/^-?\d{1,20}$/']], ['chat_id.*' => 'شناسهٔ چت باید عدد باشد.']);
        try {
            $group = $this->groups->addManually((int) $data['chat_id'], $request->user('ax_admin'));

            return back()->with('success', 'گروه «' . ($group->title ?? $group->chat_id) . '» اضافه شد و پیام آزمایشی ارسال شد.');
        } catch (Throwable $e) {
            return back()->with('error', 'گروه ذخیره شد اما پیام آزمایشی ارسال نشد: ' . $e->getMessage());
        }
    }

    public function toggle(Request $request, Group $group): RedirectResponse
    {
        $group->is_active = !$group->is_active;
        $group->save();
        $this->audit->log($request->user('ax_admin'), $group->is_active ? 'group.activated' : 'group.deactivated', $group);

        return back()->with('success', 'وضعیت گروه تغییر کرد.');
    }

    public function test(Request $request, Group $group): RedirectResponse
    {
        try {
            $this->groups->sendTestMessage($group);
            $this->audit->log($request->user('ax_admin'), 'group.test_message', $group);

            return back()->with('success', 'پیام آزمایشی ارسال شد.');
        } catch (Throwable $e) {
            return back()->with('error', 'ارسال نشد: ' . $e->getMessage());
        }
    }

    public function refreshCount(Group $group): RedirectResponse
    {
        $count = $this->groups->refreshMembersCount($group);

        return back()->with($count === null ? 'error' : 'success', $count === null ? 'دریافت تعداد اعضا ممکن نشد.' : 'تعداد اعضا به‌روز شد.');
    }

    public function members(Request $request, Group $group): RedirectResponse
    {
        $request->validate(['ids' => ['nullable', 'string', 'max:200000']]);
        $count = $this->groups->replaceManualMembers($group, (string) $request->input('ids'), $request->user('ax_admin'));

        return back()->with('success', 'فهرست اعضا (' . $count . ' شناسه) به‌صورت هش ذخیره شد. شناسه‌های خام نگه‌داری نمی‌شوند.');
    }
}
