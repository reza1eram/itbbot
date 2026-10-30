@extends('ax::layouts.admin')
@section('title', 'ربات')
@php($D = \App\Modules\AnonymousExam\Support\Display::class)
@php($canEdit = auth('ax_admin')->user()->canEdit())

@section('content')
<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>وضعیت اتصال</h2>
            @if($canEdit)
                <form method="post" action="{{ route('ax.admin.bot.check') }}" class="inline">@csrf<button class="btn btn-sm">بررسی دوباره (getMe)</button></form>
            @endif
        </div>
        <table>
            <tr><th>ربات</th><td class="ltr">&#64;{{ config('anonymous_exam.bale.username') }}</td></tr>
            <tr><th>getMe</th><td>@if($bot['ok'])<span class="badge badge-ok">OK</span> <span class="ltr">&#64;{{ $bot['username'] }}</span>@else<span class="badge badge-error">خطا</span> <span class="small">{{ $bot['error'] }}</span>@endif</td></tr>
            <tr><th>توکن</th><td>{{ ($bot['configured'] ?? false) ? 'در .env تنظیم شده' : 'تنظیم نشده (BALE_BOT_TOKEN)' }}</td></tr>
            <tr><th>حالت دریافت</th><td><span class="badge">{{ $mode }}</span> <span class="muted small">BALE_UPDATE_MODE</span></td></tr>
            <tr><th>بررسی عضویت</th><td><span class="badge">{{ $membershipMode }}</span> <span class="muted small">BALE_MEMBERSHIP_MODE</span></td></tr>
            <tr><th>دکمهٔ اعلام</th><td><span class="badge">{{ $joinButton }}</span> <span class="muted small">BALE_JOIN_BUTTON</span></td></tr>
            <tr><th>آخرین بررسی</th><td>{{ $D::dateTime($record->checked_at) }}</td></tr>
        </table>
        <p class="small muted mt">برای بررسی کامل API روی گروه واقعی: <code>php artisan exam:bot-check --chat=ID --user=ID --write-doc</code></p>
    </div>

    <div class="card">
        <div class="card-head"><h2>وب‌هوک</h2></div>
        @if($webhookError)
            <div class="alert alert-error">{{ $webhookError }}</div>
        @elseif($webhook)
            <table>
                <tr><th>آدرس</th><td class="ltr small">{{ $webhook['url'] ?: '— (خالی: حالت polling)' }}</td></tr>
                <tr><th>در انتظار</th><td>{{ $D::digits($webhook['pending_update_count'] ?? 0) }}</td></tr>
                @if(!empty($webhook['last_error_message']))<tr><th>آخرین خطا</th><td class="small">{{ $webhook['last_error_message'] }}</td></tr>@endif
            </table>
        @else
            <p class="muted">توکن تنظیم نشده است.</p>
        @endif
        <p class="small muted mt">وب‌هوک و polling هم‌زمان کار نمی‌کنند. وب‌هوک فقط HTTPS روی پورت ۴۴۳ یا ۸۸.</p>
        @if($canEdit)
            <div class="actions">
                <form method="post" action="{{ route('ax.admin.bot.webhook.set') }}" class="inline" data-confirm="وب‌هوک روی آدرس عمومی تنظیم شود؟ (polling باید متوقف باشد)">@csrf<button class="btn btn-primary btn-sm">تنظیم وب‌هوک</button></form>
                <form method="post" action="{{ route('ax.admin.bot.webhook.delete') }}" class="inline" data-confirm="وب‌هوک حذف شود؟">@csrf<button class="btn btn-sm">حذف وب‌هوک</button></form>
            </div>
        @endif
    </div>
</div>

@if($canEdit)
<div class="card">
    <h2>ارسال پیام آزمایشی به گروه</h2>
    @if($groups->isEmpty())
        <p class="muted">ابتدا یک گروه ثبت کنید.</p>
    @else
        <form method="post" action="{{ route('ax.admin.bot.test') }}" class="row">
            @csrf
            <select name="group_id">@foreach($groups as $g)<option value="{{ $g->id }}">{{ $g->title ?? $g->chat_id }}</option>@endforeach</select>
            <div><button class="btn">ارسال</button></div>
        </form>
    @endif
</div>
@endif

<div class="card">
    <h2>صف پیام‌های گروهی (outbox)</h2>
    @if($outbox->isEmpty())
        <div class="empty">پیامی در صف نیست.</div>
    @else
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>هدف</th><th>وضعیت</th><th>تلاش</th><th>خطا</th><th>زمان</th><th></th></tr></thead>
            <tbody>
            @foreach($outbox as $m)
                <tr>
                    <td>{{ $D::digits($m->id) }}</td><td>{{ $m->purpose }}</td>
                    <td><span class="badge badge-{{ $m->status }}">{{ $m->status }}</span></td>
                    <td>{{ $D::digits($m->attempts) }}</td>
                    <td class="small ltr">{{ $m->last_error }}</td>
                    <td class="small nowrap">{{ $D::dateTime($m->updated_at) }}</td>
                    <td>@if($canEdit && $m->status !== 'sent')<form method="post" action="{{ route('ax.admin.bot.outbox.retry', $m->id) }}" class="inline">@csrf<button class="btn btn-sm">تلاش دوباره</button></form>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
