<?php

namespace App\Modules\AnonymousExam\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('ax_admin')->user();
        if (!$admin || !$admin->is_active) {
            if ($admin) {
                Auth::guard('ax_admin')->logout();
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => 'نشست شما منقضی شده است.'], 401);
            }

            return redirect()->guest(route('ax.admin.login'));
        }
        Auth::shouldUse('ax_admin');

        return $next($request);
    }
}
