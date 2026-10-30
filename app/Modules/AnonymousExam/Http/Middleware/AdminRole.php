<?php

namespace App\Modules\AnonymousExam\Http\Middleware;

use App\Modules\AnonymousExam\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ax.role:edit  -> owner or editor (viewer is read-only)
 * ax.role:owner -> owner only
 */
class AdminRole
{
    public function handle(Request $request, Closure $next, string $level = 'edit'): Response
    {
        /** @var AdminUser|null $admin */
        $admin = $request->user('ax_admin');
        if (!$admin || !$admin->is_active) {
            abort(403);
        }

        $allowed = $level === 'owner' ? $admin->isOwner() : $admin->canEdit();
        if (!$allowed) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'شما اجازهٔ این کار را ندارید.'], 403);
            }

            return back()->with('error', 'شما اجازهٔ این کار را ندارید.');
        }

        return $next($request);
    }
}
