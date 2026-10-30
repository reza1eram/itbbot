@extends('ax::layouts.admin')
@section('title', $exam->title)
@php($D = \App\Modules\AnonymousExam\Support\Display::class)
@php($canEdit = auth('ax_admin')->user()->canEdit())

@section('content')
<div class="card">
    <div class="card-head">
        <div class="actions">@include('ax::admin.partials.status', ['status' => $exam->status]) <span class="badge">{{ $exam->mode === 'quiz' ? 'آزمون نمره‌دار' : 'نظرسنجی' }}</span></div>
        <div class="actions">
            @if($canEdit && $exam->status !== 'archived')<a class="btn btn-sm" href="{{ route('ax.admin.exams.edit', $exam) }}">ویرایش</a>@endif
            <a class="btn btn-sm" href="{{ route('ax.admin.exams.preview', $exam) }}">پیش‌نمایش</a>
            <a class="btn btn-sm" href="{{ route('ax.admin.exams.results', $exam) }}">نتایج</a>
        </div>
    </div>
    <div class="grid grid-4" data-live-url="{{ route('ax.admin.api.live') }}">
        <div class="stat"><div class="label">شرکت‌کننده (ثبت‌شده)</div><div class="value" data-live-count="{{ $exam->id }}">{{ $D::digits($counts['submitted']) }}</div></div>
        <div class="stat"><div class="label">نرخ مشارکت</div><div class="value">{{ $counts['rate'] !== null ? $D::digits($counts['rate']) . '٪' : '—' }}</div>
            <div class="small muted">{{ $counts['members'] ? 'از ' . $D::digits($counts['members']) . ' عضو' : 'تعداد اعضا: از صفحهٔ گروه‌ها' }}</div></div>
        <div class="stat"><div class="label">شروع</div><div class="value small">{{ $D::dateTime($exam->starts_at) }}</div></div>
        <div class="stat"><div class="label">پایان</div><div class="value small">{{ $D::dateTime($exam->ends_at) }}</div></div>
    </div>
    <table class="mt">
        <tr><th>گروه</th><td>{{ $exam->group?->title ?? '—' }}</td></tr>
        <tr><th>کد عمومی</th><td class="ltr">{{ $exam->public_code }}</td></tr>
        <tr><th>لینک عمیق</th><td class="ltr small">https://ble.ir/{{ config('anonymous_exam.bale.username') }}?start=exam_{{ $exam->public_code }}</td></tr>
        <tr><th>سؤال‌ها</th><td>{{ $D::digits($questionsCount) }}</td></tr>
        <tr><th>یادآوری‌ها</th><td>{{ $exam->reminders ? $D::digits(implode('، ', $exam->reminders)) . ' دقیقه قبل از پایان' : '—' }}</td></tr>
        <tr><th>نمایش نتایج</th><td>{{ $exam->results_visibility === 'live' ? 'زنده' : 'بعد از پایان' }} — حداقل {{ $D::digits($exam->min_responses_to_show) }} پاسخ</td></tr>
        @if($exam->closed_at)<tr><th>بسته شده</th><td>{{ $D::dateTime($exam->closed_at) }}</td></tr>@endif
    </table>
</div>

@if($problems)
    <div class="alert alert-warn"><strong>پیش از انتشار برطرف کنید:</strong><ul>@foreach($problems as $p)<li>{{ $p }}</li>@endforeach</ul></div>
@endif

@if($canEdit)
<div class="card">
    <h2>اجرای آزمون</h2>
    <div class="actions">
        @if($exam->status === 'draft')
            <form method="post" action="{{ route('ax.admin.exams.publish', $exam) }}" class="inline" data-confirm="آزمون منتشر شود؟ در زمان شروع خودکار باز و در گروه اعلام می‌شود.">@csrf<button class="btn btn-primary" @disabled($problems)>انتشار (زمان‌بندی)</button></form>
        @endif
        @if(in_array($exam->status, ['draft', 'scheduled']))
            <form method="post" action="{{ route('ax.admin.exams.open', $exam) }}" class="inline" data-confirm="آزمون همین حالا باز شود؟ پس از باز شدن، سؤال‌ها قفل می‌شوند.">@csrf<button class="btn" @disabled($problems)>شروع فوری</button></form>
        @endif
        @if($exam->status === 'open')
            <form method="post" action="{{ route('ax.admin.exams.announce', $exam) }}" class="inline" data-confirm="اعلام آزمون دوباره در گروه ارسال شود؟ (اعلام قبلی حذف می‌شود)">@csrf<button class="btn">ارسال اعلام به گروه</button></form>
            <form method="post" action="{{ route('ax.admin.exams.remind', $exam) }}" class="inline" data-confirm="یادآوری در گروه ارسال شود؟">@csrf<button class="btn">ارسال یادآوری</button></form>
        @endif
    </div>

    @if($exam->status === 'open')
        <div class="card mt">
            <h3>پایان آزمون</h3>
            <p class="small muted">با پایان آزمون، لینک‌های باز باطل می‌شوند، هیچ پاسخ جدیدی پذیرفته نمی‌شود و نتایج باز می‌شود. برای تأیید، کد آزمون <code class="ltr">{{ $exam->public_code }}</code> را بنویسید.</p>
            <form method="post" action="{{ route('ax.admin.exams.close', $exam) }}" class="row" data-confirm-code="{{ $exam->public_code }}">
                @csrf
                <input type="text" name="confirm_code" class="ltr" autocomplete="off" placeholder="{{ $exam->public_code }}">
                <div><button type="submit" class="btn btn-danger">پایان آزمون</button></div>
            </form>
        </div>
    @endif
</div>
@endif

<div class="card">
    <h2>پیام‌های این آزمون در گروه</h2>
    @if($outbox->isEmpty())
        <div class="empty">هنوز پیامی ارسال نشده است.</div>
    @else
        <table>
            <thead><tr><th>نوع</th><th>وضعیت</th><th>خطا</th><th>زمان</th></tr></thead>
            <tbody>
            @foreach($outbox as $m)
                <tr><td>{{ ['announce' => 'اعلام', 'reminder' => 'یادآوری', 'close' => 'پایان'][$m->purpose] ?? $m->purpose }}</td>
                    <td><span class="badge badge-{{ $m->status }}">{{ $m->status }}</span></td>
                    <td class="small ltr">{{ $m->last_error }}</td><td class="small nowrap">{{ $D::dateTime($m->updated_at) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
