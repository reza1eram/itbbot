<?php

namespace App\Modules\AnonymousExam\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\AnonymousExam\Http\Requests\LoginRequest;
use App\Modules\AnonymousExam\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::guard('ax_admin')->check()) {
            return redirect()->route('ax.admin.dashboard');
        }

        return view('ax::admin.login');
    }

    public function login(LoginRequest $request, AuditService $audit): RedirectResponse
    {
        $ok = Auth::guard('ax_admin')->attempt([
            'username' => $request->input('username'),
            'password' => $request->input('password'),
            'is_active' => true,
        ]);

        if (!$ok) {
            return back()->withInput($request->only('username'))->with('error', 'نام کاربری یا رمز عبور نادرست است.');
        }

        $request->session()->regenerate();
        $admin = Auth::guard('ax_admin')->user();
        $admin->forceFill(['last_login_at' => now()])->save();
        $audit->log($admin, 'admin.login');

        return redirect()->intended(route('ax.admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('ax_admin')->logout();
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();

        return redirect()->route('ax.admin.login');
    }

    public function showPassword(): View
    {
        return view('ax::admin.password');
    }

    public function updatePassword(Request $request, AuditService $audit): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:10', 'max:200', 'confirmed'],
        ], [
            'password.min' => 'رمز جدید باید دست‌کم ۱۰ کاراکتر باشد.',
            'password.confirmed' => 'تکرار رمز با رمز جدید یکسان نیست.',
            'required' => 'همهٔ فیلدها الزامی است.',
        ]);

        $admin = $request->user('ax_admin');
        if (!Hash::check($request->input('current_password'), $admin->password)) {
            return back()->with('error', 'رمز فعلی نادرست است.');
        }
        $admin->password = $request->input('password');
        $admin->save();
        $audit->log($admin, 'admin.password_changed', $admin);

        return back()->with('success', 'رمز عبور تغییر کرد.');
    }
}
