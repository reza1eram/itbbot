@extends('ax::layouts.admin')
@section('title', 'داشبورد')
@php($D = \App\Modules\AnonymousExam\Support\Display::class)

@section('content')
@unless(\App\Modules\AnonymousExam\Services\SchedulerHealth::cronHealthy())
    @php($lastWeb = \App\Modules\AnonymousExam\Services\SchedulerHealth::lastTick('web'))
    <div class="alert alert-warn">
        <strong>کرون‌جاب زمان‌بند در چند دقیقهٔ اخیر اجرا نشده است.</strong>
        باز و بسته شدن خودکار آزمون‌ها، یادآوری‌ها و انتقال پاسخ‌های در صف به آن وابسته است.
        زمان‌بند پشتیبان با هر بازدید از سایت کار می‌کند@if($lastWeb) (آخرین اجرا: {{ $D::dateTime($lastWeb) }})@endif، ولی جایگزین کامل cron نیست.
        در cPanel یک کرون‌جاب «Once Per Minute» با این دستور بسازید:
        <div class="ltr small mt"><code>/path/to/php {{ base_path('artisan') }} schedule:run -q</code></div>
    </div>
@endunless
<div class="grid grid-4">
    <div class="stat">
        <div class="label">وضعیت ربات</div>
        <div class="value">
            @if($bot['ok'])<span class="badge badge-ok">متصل</span>@else<span class="badge badge-error">قطع</span>@endif
        </div>
        <div class="small muted ltr">{{ $bot['ok'] ? '@' . $bot['username'] : ($bot['error'] ?? '') }}</div>
    </div>
    <div class="stat"><div class="label">آزمون‌های فعال</div><div class="value">{{ $D::digits($active->count()) }}</div></div>
    <div class="stat"><div class="label">گروه‌های فعال</div><div class="value">{{ $D::digits($groupsCount) }}</div></div>
    <div class="stat"><div class="label">همهٔ آزمون‌ها</div><div class="value">{{ $D::digits($examsCount) }}</div></div>
</div>

<div class="card mt">
    <div class="card-head">
        <h2>آزمون‌های باز و زمان‌بندی‌شده</h2>
        @if(auth('ax_admin')->user()->canEdit())
            <a class="btn btn-primary" href="{{ route('ax.admin.exams.create') }}">+ آزمون جدید</a>
        @endif
    </div>
    @if($active->isEmpty())
        <div class="empty">آزمون فعالی وجود ندارد.</div>
    @else
        <div class="table-wrap">
            <table data-live-url="{{ route('ax.admin.api.live') }}">
                <thead><tr><th>عنوان</th><th>گروه</th><th>وضعیت</th><th>پایان</th><th>شرکت‌کننده (زنده)</th><th></th></tr></thead>
                <tbody>
                @foreach($active as $row)
                    <tr>
                        <td>{{ $row['exam']->title }}</td>
                        <td>{{ $row['exam']->group?->title ?? '—' }}</td>
                        <td>@include('ax::admin.partials.status', ['status' => $row['exam']->status])</td>
                        <td class="nowrap">{{ $D::dateTime($row['exam']->ends_at) }}</td>
                        <td><strong data-live-count="{{ $row['exam']->id }}">{{ $D::digits($row['counts']['submitted']) }}</strong>
                            @if($row['counts']['members'])<span class="muted small">از {{ $D::digits($row['counts']['members']) }}</span>@endif
                        </td>
                        <td><a class="btn btn-sm" href="{{ route('ax.admin.exams.show', $row['exam']) }}">مدیریت</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="card">
    <div class="card-head"><h2>آخرین خطاهای ارسال</h2><a href="{{ route('ax.admin.bot') }}" class="small">همهٔ پیام‌ها</a></div>
    @if($failures->isEmpty())
        <div class="empty">خطایی ثبت نشده است.</div>
    @else
        <table>
            <thead><tr><th>هدف</th><th>تلاش</th><th>خطا</th><th>زمان</th></tr></thead>
            <tbody>
            @foreach($failures as $f)
                <tr><td>{{ $f->purpose }}</td><td>{{ $D::digits($f->attempts) }}</td><td class="small ltr">{{ $f->last_error }}</td><td class="nowrap small">{{ $D::dateTime($f->updated_at) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
