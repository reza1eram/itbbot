<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'آزمون ناشناس')</title>
    <link rel="stylesheet" href="{{ asset('exam-admin/css/app.css') }}?v=1">
</head>
<body>
<div class="px">
    @yield('content')
</div>
@stack('scripts')
</body>
</html>
