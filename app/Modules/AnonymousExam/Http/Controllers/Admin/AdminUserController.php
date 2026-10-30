<?php

namespace App\Modules\AnonymousExam\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\AnonymousExam\Models\AdminUser;
use App\Modules\AnonymousExam\Models\AuditLog;
use App\Modules\AnonymousExam\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function index(): View
    {
        return view('ax::admin.admins', ['admins' => AdminUser::query()->orderBy('id')->get()]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('ax_admin_users', 'username')],
            'password' => ['required', 'string', 'min:10', 'max:200'],
            'role' => ['required', Rule::in(AdminUser::ROLES)],
            'bale_admin_chat_id' => ['nullable', 'regex:/^\d{1,20}$/', Rule::unique('ax_admin_users', 'bale_admin_chat_id')],
        ], ['password.min' => 'رمز باید دست‌کم ۱۰ کاراکتر باشد.', 'username.unique' => 'این نام کاربری قبلاً ثبت شده است.']);

        $admin = AdminUser::query()->create($data + ['is_active' => true]);
        $audit->log($request->user('ax_admin'), 'admin.create', $admin, ['role' => $admin->role]);

        return back()->with('success', 'مدیر جدید ساخته شد.');
    }

    public function update(Request $request, AdminUser $admin, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(AdminUser::ROLES)],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:10', 'max:200'],
            'bale_admin_chat_id' => ['nullable', 'regex:/^\d{1,20}$/', Rule::unique('ax_admin_users', 'bale_admin_chat_id')->ignore($admin->id)],
        ]);

        $me = $request->user('ax_admin');
        $active = $request->boolean('is_active');
        $isLastOwner = $admin->isOwner() && AdminUser::query()->where('role', AdminUser::ROLE_OWNER)->where('is_active', true)->count() <= 1;
        if ($isLastOwner && ($data['role'] !== AdminUser::ROLE_OWNER || !$active)) {
            return back()->with('error', 'آخرین مالک فعال را نمی‌توان غیرفعال یا تنزل داد.');
        }

        $admin->role = $data['role'];
        $admin->is_active = $active;
        $admin->bale_admin_chat_id = $data['bale_admin_chat_id'] ?? null;
        if (!empty($data['password'])) {
            $admin->password = $data['password'];
        }
        $admin->save();
        $audit->log($me, 'admin.update', $admin, ['role' => $admin->role, 'active' => $admin->is_active, 'password_reset' => !empty($data['password'])]);

        return back()->with('success', 'اطلاعات مدیر به‌روز شد.');
    }

    public function audit(Request $request): View
    {
        return view('ax::admin.audit', [
            'logs' => AuditLog::query()->with('admin')->latest('id')->paginate(50),
        ]);
    }
}
