<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'پنل') — آزمون ناشناس</title>
    <link rel="stylesheet" href="{{ asset('exam-admin/css/app.css') }}?v=1">
</head>
<body>
@php($me = auth('ax_admin')->user())
@php($route = request()->route()?->getName() ?? '')
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand"><span class="dot"></span> آزمون ناشناس بله</div>
        <nav class="nav">
            <a href="{{ route('ax.admin.dashboard') }}" @class(['active' => $route === 'ax.admin.dashboard'])>داشبورد</a>
            <a href="{{ route('ax.admin.exams') }}" @class(['active' => str_starts_with($route, 'ax.admin.exams')])>آزمون‌ها</a>
            <a href="{{ route('ax.admin.groups') }}" @class(['active' => str_starts_with($route, 'ax.admin.groups')])>گروه‌ها</a>
            <a href="{{ route('ax.admin.bot') }}" @class(['active' => str_starts_with($route, 'ax.admin.bot')])>ربات</a>
            <div class="sep"></div>
            <a href="{{ route('ax.admin.templates') }}" @class(['active' => $route === 'ax.admin.templates'])>قالب پیام‌ها</a>
            <a href="{{ route('ax.admin.settings') }}" @class(['active' => $route === 'ax.admin.settings'])>تنظیمات و حریم خصوصی</a>
            @if($me?->isOwner())
                <a href="{{ route('ax.admin.admins') }}" @class(['active' => $route === 'ax.admin.admins'])>مدیران</a>
            @endif
            <a href="{{ route('ax.admin.audit') }}" @class(['active' => $route === 'ax.admin.audit'])>گزارش عملیات</a>
            <div class="sep"></div>
            <a href="{{ route('ax.admin.password') }}" @class(['active' => $route === 'ax.admin.password'])>تغییر رمز</a>
        </nav>
    </aside>
    <main class="main">
        <div class="topbar">
            <div class="actions">
                <button type="button" class="btn btn-sm menu-toggle" data-toggle-sidebar>☰</button>
                <h1>@yield('heading', View::yieldContent('title'))</h1>
            </div>
            <div class="who">
                <span>{{ $me?->name }} <span class="badge">{{ ['owner' => 'مالک', 'editor' => 'ویرایشگر', 'viewer' => 'بیننده'][$me?->role] ?? '' }}</span></span>
                <form method="post" action="{{ route('ax.admin.logout') }}" class="inline">@csrf<button class="btn btn-sm">خروج</button></form>
            </div>
        </div>

        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="alert alert-error">{{ session('error') }}</div>@endif
        @if($errors->any())
            <div class="alert alert-error"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif

        @yield('content')
    </main>
</div>
<script src="{{ asset('exam-admin/js/app.js') }}?v=1"></script>
@stack('scripts')
</body>
</html>
