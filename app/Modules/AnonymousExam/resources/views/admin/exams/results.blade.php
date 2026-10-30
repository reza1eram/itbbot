@extends('ax::layouts.admin')
@section('title', 'نتایج: ' . $exam->title)
@php($D = \App\Modules\AnonymousExam\Support\Display::class)
@php($c = $summary['counts'])

@section('content')
<div class="grid grid-4">
    <div class="stat"><div class="label">شرکت‌کنندگان</div><div class="value">{{ $D::digits($c['submitted']) }}</div></div>
    <div class="stat"><div class="label">نرخ مشارکت</div><div class="value">{{ $c['rate'] !== null ? $D::digits($c['rate']) . '٪' : '—' }}</div></div>
    <div class="stat"><div class="label">در حال پاسخ / لینک گرفته</div><div class="value">{{ $D::digits($c['in_progress']) }}</div></div>
    <div class="stat"><div class="label">وضعیت</div><div class="value">@include('ax::admin.partials.status', ['status' => $exam->status])</div></div>
</div>

@if($c['pooled'] > 0)
    <div class="alert alert-warn mt">
        <strong>{{ $D::digits($c['pooled']) }} پاسخ رمزنگاری‌شده در صف انتقال است.</strong>
        برای حفظ ناشناسی (ANON_POOL_MODE)، پاسخ‌ها ابتدا رمزنگاری‌شده نگه داشته می‌شوند و به‌صورت دسته‌ای با ترتیب تصادفی به نتایج منتقل می‌شوند:
        خودکار هر دقیقه وقتی دست‌کم {{ $D::digits($minBatch) }} پاسخ در صف باشد، یا هنگام پایان آزمون.
        @unless($cronHealthy)
            <br><strong>زمان‌بند (cron) در چند دقیقهٔ اخیر اجرا نشده است</strong>؛ کرون‌جاب <code class="ltr">artisan schedule:run</code> را در cPanel بررسی کنید.
        @endunless
        @if(auth('ax_admin')->user()->canEdit())
            <form method="post" action="{{ route('ax.admin.exams.flush', $exam) }}" class="mt">@csrf<button class="btn btn-sm">انتقال پاسخ‌های در صف</button></form>
        @endif
    </div>
@endif

<div class="alert alert-info small mt">فهرست اسامی شرکت‌کنندگان وجود ندارد و قابل ساختن هم نیست؛ فقط تعداد نمایش داده می‌شود. پاسخ‌های متنی به ترتیب تصادفی نمایش داده می‌شوند.</div>

@if(!$summary['visibility']['visible'])
    <div class="card">
        <div class="empty">
            @if($summary['visibility']['reason'] === 'locked_until_close')
                🔒 نتایج تفکیکی تا پایان آزمون قفل است (تنظیم «فقط بعد از پایان»).
            @else
                🔒 برای حفظ ناشناسی، نتایج تفکیکی پس از رسیدن به دست‌کم {{ $D::digits($summary['min']) }} پاسخ نمایش داده می‌شود (اکنون {{ $D::digits($c['responses']) }} پاسخ منتقل‌شده@if($c['pooled'] > 0) و {{ $D::digits($c['pooled']) }} پاسخ در صف@endif).
            @endif
        </div>
    </div>
@else
    <div class="card">
        <div class="card-head"><h2>خروجی</h2><a class="btn" href="{{ route('ax.admin.exams.csv', $exam) }}">دانلود CSV (اکسل)</a></div>
        <p class="small muted">ردیف‌ها با ترتیب تصادفی و بدون زمان ثبت هستند.</p>
    </div>

    @if($summary['scores'] && $summary['scores']['count'])
        @php($s = $summary['scores'])
        <div class="card">
            <h2>نمره‌ها</h2>
            <div class="grid grid-4">
                <div class="stat"><div class="label">میانگین</div><div class="value">{{ $D::number($s['avg'], 2) }}</div><div class="small muted">از {{ $D::number($s['max_score'], 2) }}</div></div>
                <div class="stat"><div class="label">میانه</div><div class="value">{{ $D::number($s['median'], 2) }}</div></div>
                <div class="stat"><div class="label">کمینه</div><div class="value">{{ $D::number($s['min'], 2) }}</div></div>
                <div class="stat"><div class="label">بیشینه</div><div class="value">{{ $D::number($s['max'], 2) }}</div></div>
            </div>
            @php($hmax = max(1, max($s['histogram']['values'])))
            <h3 class="mt">هیستوگرام</h3>
            <div class="histogram">
                @foreach($s['histogram']['values'] as $v)
                    <div class="col" data-hpct="{{ round($v * 100 / $hmax, 1) }}" title="{{ $v }}"></div>
                @endforeach
            </div>
            <div class="histogram-labels">@foreach($s['histogram']['labels'] as $l)<span>{{ $l }}</span>@endforeach</div>
        </div>
    @endif

    @foreach($summary['questions'] as $i => $q)
        <div class="card">
            <h3>{{ $D::digits($i + 1) }}. {{ $q['text'] }}</h3>
            <p class="small muted">پاسخ داده: {{ $D::digits($q['answered']) }} از {{ $D::digits($q['responses']) }}</p>
            @if(isset($q['options']))
                @php($tot = max(1, $q['answered']))
                <div class="bars">
                    @foreach($q['options'] as $o)
                        <div class="bar-row">
                            <span>{{ $o['text'] }} @if($o['correct'])<span class="badge badge-ok">صحیح</span>@endif</span>
                            <div class="track"><div @class(['fill', 'correct' => $o['correct']]) data-pct="{{ round($o['count'] * 100 / $tot, 1) }}"></div></div>
                            <span class="nowrap">{{ $D::digits($o['count']) }} ({{ $D::digits(round($o['count'] * 100 / $tot)) }}٪)</span>
                        </div>
                    @endforeach
                </div>
            @elseif(isset($q['texts']))
                @if($q['texts'])
                    <ul class="text-answers">@foreach($q['texts'] as $t)<li>{{ $t }}</li>@endforeach</ul>
                @else
                    <div class="empty">پاسخ متنی ثبت نشده است.</div>
                @endif
            @elseif(isset($q['distribution']))
                <p>میانگین: <strong>{{ $D::number($q['avg'], 2) }}</strong></p>
                @php($dmax = max(1, $q['answered']))
                <div class="bars">
                    @foreach($q['distribution'] as $val => $cnt)
                        <div class="bar-row"><span>{{ $D::digits($val) }}</span><div class="track"><div class="fill" data-pct="{{ round($cnt * 100 / $dmax, 1) }}"></div></div><span>{{ $D::digits($cnt) }}</span></div>
                    @endforeach
                </div>
            @else
                <p>میانگین: <strong>{{ $D::number($q['avg'], 2) }}</strong> — کمینه: {{ $D::number($q['min'], 2) }} — بیشینه: {{ $D::number($q['max'], 2) }}</p>
            @endif
        </div>
    @endforeach
@endif
@endsection
