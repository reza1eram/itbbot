<?php

namespace App\Modules\AnonymousExam\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\AnonymousExam\Services\AuditService;
use App\Modules\AnonymousExam\Services\TemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TemplateController extends Controller
{
    public function index(TemplateService $templates): View
    {
        return view('ax::admin.templates', ['templates' => $templates->all()]);
    }

    public function update(Request $request, TemplateService $templates, AuditService $audit): RedirectResponse
    {
        $request->validate(['templates' => ['required', 'array'], 'templates.*' => ['nullable', 'string', 'max:4000']]);
        $known = array_keys($templates->defaults());
        $changed = [];
        foreach ((array) $request->input('templates') as $key => $body) {
            if (in_array($key, $known, true)) {
                $before = $templates->all()[$key]['override'] ?? null;
                $templates->save($key, $body);
                if ($before !== ($templates->all()[$key]['override'] ?? null)) {
                    $changed[] = $key;
                }
            }
        }
        $audit->log($request->user('ax_admin'), 'templates.update', null, ['keys' => $changed]);

        return back()->with('success', 'قالب پیام‌ها ذخیره شد.');
    }
}
