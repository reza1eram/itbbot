@extends('ax::layouts.participant')
@section('title', $exam->title)
@php($D = \App\Modules\AnonymousExam\Support\Display::class)

@section('content')
<form id="px-form" novalidate
      data-submit-url="{{ route('ax.x.submit') }}"
      data-draft-key="ax-draft-{{ $exam->public_code }}"
      data-mode="{{ $exam->display_mode }}"
      data-deadline="{{ $deadline ?? '' }}"
      data-has-duration="{{ $hasDuration ? '1' : '0' }}">
    <div class="px-head">
        <div class="anon-note">🔒 <span>پاسخ‌های شما ناشناس ثبت می‌شود. <a href="{{ route('ax.x.privacy') }}" target="_blank" rel="noopener noreferrer">چگونه؟</a></span></div>
        <h1>{{ $exam->title }}</h1>
        @if($exam->description)<p class="muted">{{ $exam->description }}</p>@endif
        <div class="progress"><div class="fill" id="px-progress"></div></div>
        <div class="px-meta">
            <span id="px-progress-text"></span>
            @if($deadline)<span>زمان باقی‌مانده: <span class="timer" id="px-timer">—</span></span>@endif
        </div>
    </div>

    <div id="px-alert" class="alert alert-error hidden" role="alert"></div>

    @foreach($questions as $i => $q)
        <fieldset class="question" data-qid="{{ $q['id'] }}" data-type="{{ $q['type'] }}" data-required="{{ $q['required'] ? '1' : '0' }}" data-step="{{ $i }}">
            <legend class="q-title">{{ $D::digits($i + 1) }}. {{ $q['text'] }} @if($q['required'])<span class="req" aria-label="الزامی">*</span>@endif</legend>
            @if($q['help'])<div class="q-help">{{ $q['help'] }}</div>@endif

            @switch($q['type'])
                @case('single')
                @case('true_false')
                    @foreach($q['options'] as $o)
                        <label class="choice"><input type="radio" name="q{{ $q['id'] }}" value="{{ $o['id'] }}"><span>{{ $o['text'] }}</span></label>
                    @endforeach
                    @break
                @case('multi')
                    @foreach($q['options'] as $o)
                        <label class="choice"><input type="checkbox" name="q{{ $q['id'] }}" value="{{ $o['id'] }}"><span>{{ $o['text'] }}</span></label>
                    @endforeach
                    @break
                @case('rating')
                    <div class="rating">
                        @for($v = $q['range'][0]; $v <= $q['range'][1]; $v++)
                            <label><input type="radio" name="q{{ $q['id'] }}" value="{{ $v }}">{{ $D::digits($v) }}</label>
                        @endfor
                    </div>
                    @break
                @case('number')
                    <input type="text" inputmode="decimal" name="q{{ $q['id'] }}" class="ltr" autocomplete="off"
                           @if($q['range'][0] !== null) data-min="{{ $q['range'][0] }}" @endif @if($q['range'][1] !== null) data-max="{{ $q['range'][1] }}" @endif>
                    @break
                @case('short_text')
                    <input type="text" name="q{{ $q['id'] }}" maxlength="{{ $q['max_length'] }}" autocomplete="off">
                    <div class="counter" data-counter-for="q{{ $q['id'] }}"></div>
                    <div class="text-warning">متن خود را طوری ننویسید که هویتتان را لو بدهد.</div>
                    @break
                @case('long_text')
                    <textarea name="q{{ $q['id'] }}" maxlength="{{ $q['max_length'] }}" rows="5"></textarea>
                    <div class="counter" data-counter-for="q{{ $q['id'] }}"></div>
                    <div class="text-warning">متن خود را طوری ننویسید که هویتتان را لو بدهد.</div>
                    @break
            @endswitch
            <div class="q-error hidden"></div>
        </fieldset>
    @endforeach

    <div class="px-footer">
        <div class="inner">
            <button type="button" class="btn hidden" id="px-prev">قبلی</button>
            <span class="grow small muted" id="px-draft-state">پیش‌نویس فقط روی همین دستگاه ذخیره می‌شود.</span>
            <button type="button" class="btn btn-primary hidden" id="px-next">بعدی</button>
            <button type="submit" class="btn btn-primary" id="px-submit">ثبت پاسخ‌ها</button>
        </div>
    </div>
</form>

<div class="modal-backdrop hidden" id="px-confirm" role="dialog" aria-modal="true" aria-labelledby="px-confirm-title">
    <div class="modal">
        <h3 id="px-confirm-title">ثبت نهایی پاسخ‌ها؟</h3>
        <p id="px-confirm-text">پس از ثبت، امکان تغییر پاسخ‌ها وجود ندارد.</p>
        <div class="actions">
            <button type="button" class="btn btn-primary" id="px-confirm-yes">بله، ثبت شود</button>
            <button type="button" class="btn" id="px-confirm-no">بازبینی</button>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('exam-admin/js/participant.js') }}?v=1"></script>
@endpush
