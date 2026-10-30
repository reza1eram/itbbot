@extends('ax::layouts.admin')
@section('title', 'تنظیمات و حریم خصوصی')
@php($D = \App\Modules\AnonymousExam\Support\Display::class)
@php($canEdit = auth('ax_admin')->user()->canEdit())

@section('content')
<form method="post" action="{{ route('ax.admin.settings.update') }}" class="card">
    @csrf
    <h2>پیش‌فرض‌ها</h2>
    <div class="row">
        <div class="field"><label>مدت اعتبار لینک یک‌بارمصرف (دقیقه)</label><input type="number" name="token_ttl_minutes" value="{{ $tokenTtl }}" min="1" max="1440" @disabled(!$canEdit)></div>
        <div class="field"><label>حداقل پاسخ برای نمایش نتایج (پیش‌فرض آزمون جدید)</label><input type="number" name="default_min_responses" value="{{ $minResponses }}" min="1" max="1000" @disabled(!$canEdit)></div>
        <div class="field"><label>یادآوری‌های پیش‌فرض (دقیقه)</label><input type="text" name="default_reminders" value="{{ $reminders }}" class="ltr" @disabled(!$canEdit)></div>
    </div>
    <h2 class="mt">متن توضیح ناشناسی</h2>
    <p class="small muted">در فرمان /privacy ربات و صفحهٔ «چگونه؟» شرکت‌کننده نمایش داده می‌شود. خالی = متن پیش‌فرض.</p>
    <textarea name="privacy_text" rows="10" @disabled(!$canEdit)>{{ $privacyText }}</textarea>
    @if($canEdit)<button class="btn btn-primary mt">ذخیره</button>@endif
</form>

<div class="card">
    <h2>تنظیمات فایل ‎.env (فقط خواندنی)</h2>
    <table>
        <tr><th>BALE_UPDATE_MODE</th><td class="ltr">{{ $env['update_mode'] }}</td></tr>
        <tr><th>BALE_MEMBERSHIP_MODE</th><td class="ltr">{{ $env['membership_mode'] }}</td></tr>
        <tr><th>BALE_JOIN_BUTTON</th><td class="ltr">{{ $env['join_button'] }}</td></tr>
        <tr><th>ANON_POOL_MODE</th><td class="ltr">{{ $env['pool_mode'] }}</td></tr>
        <tr><th>آدرس عمومی لینک‌ها</th><td class="ltr">{{ $env['public_url'] }}</td></tr>
    </table>
    <p class="small muted mt">سرّها (توکن ربات، ANON_HMAC_SECRET، APP_KEY) هرگز در پنل نمایش داده نمی‌شوند. ANON_HMAC_SECRET را هرگز تغییر ندهید.</p>
</div>
@endsection
