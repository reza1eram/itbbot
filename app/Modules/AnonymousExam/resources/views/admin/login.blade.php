<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>ورود — آزمون ناشناس</title>
    <link rel="stylesheet" href="{{ asset('exam-admin/css/app.css') }}?v=1">
</head>
<body>
<div class="auth-wrap">
    <div class="card auth-card">
        <h1>ورود به پنل آزمون ناشناس</h1>
        <p class="muted small">ربات &#64;{{ config('anonymous_exam.bale.username') }}</p>
        @if(session('error'))<div class="alert alert-error">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif
        <form method="post" action="{{ route('ax.admin.login.submit') }}">
            @csrf
            <div class="field">
                <label for="username">نام کاربری</label>
                <input type="text" id="username" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus class="ltr">
            </div>
            <div class="field">
                <label for="password">رمز عبور</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required class="ltr">
            </div>
            <button class="btn btn-primary btn-block">ورود</button>
        </form>
    </div>
</div>
</body>
</html>
