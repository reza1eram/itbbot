<?php

namespace App\Modules\AnonymousExam\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\AnonymousExam\Services\AuditService;
use App\Modules\AnonymousExam\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(SettingsService $settings): View
    {
        return view('ax::admin.settings', [
            'privacyText' => $settings->privacyText(),
            'tokenTtl' => $settings->tokenTtlMinutes(),
            'minResponses' => $settings->defaultMinResponses(),
            'reminders' => implode(', ', $settings->defaultReminders()),
            'env' => [
                'update_mode' => config('anonymous_exam.bale.update_mode'),
                'membership_mode' => config('anonymous_exam.bale.membership_mode'),
                'join_button' => config('anonymous_exam.bale.join_button'),
                'pool_mode' => config('anonymous_exam.anon.pool_mode') ? 'on' : 'off',
                'public_url' => config('anonymous_exam.public_url') ?: config('app.url'),
            ],
        ]);
    }

    public function update(Request $request, SettingsService $settings, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'privacy_text' => ['nullable', 'string', 'max:4000'],
            'token_ttl_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'default_min_responses' => ['required', 'integer', 'min:1', 'max:1000'],
            'default_reminders' => ['nullable', 'string', 'max:100'],
        ]);

        $settings->put('privacy_text', trim((string) ($data['privacy_text'] ?? '')) ?: null);
        $settings->put('token_ttl_minutes', (string) $data['token_ttl_minutes']);
        $settings->put('default_min_responses', (string) $data['default_min_responses']);
        $settings->put('default_reminders', implode(',', SettingsService::parseReminders($data['default_reminders'] ?? '')));
        $audit->log($request->user('ax_admin'), 'settings.update');

        return back()->with('success', 'تنظیمات ذخیره شد.');
    }
}
