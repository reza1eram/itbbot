@extends('ax::layouts.participant')
@section('title', 'پاسخ شما ثبت شد')
@php($D = \App\Modules\AnonymousExam\Support\Display::class)

@section('content')
<div class="card result-box" id="px-done">
    <h1>✅ پاسخ شما ثبت شد</h1>
    <p class="muted">سپاس از مشارکت شما. پاسخ‌ها بدون هیچ پیوندی به هویت شما ذخیره شد و جلسهٔ شما بسته شد.</p>

    @if(is_array($result) && isset($result['score']))
        <p>نمرهٔ شما</p>
        <div class="big">{{ $D::number($result['score'], 2) }} <span class="small muted">از {{ $D::number($result['max_score'], 2) }}</span></div>
    @endif

    @if(is_array($result) && !empty($result['questions']))
        <div class="mt">
            @foreach($result['questions'] as $q)
                <div class="review-item">
                    <div><strong>{{ $q['ok'] ? '✔' : '✘' }}</strong> {{ $q['text'] }}</div>
                    <div class="small">پاسخ شما: {{ $q['chosen'] ? implode('، ', $q['chosen']) : '—' }}</div>
                    @unless($q['ok'])<div class="small">پاسخ صحیح: {{ implode('، ', $q['correct']) }}</div>@endunless
                </div>
            @endforeach
        </div>
    @endif
    <p class="small muted mt">می‌توانید این صفحه را ببندید.</p>
</div>
@endsection

@push('scripts')
<script src="{{ asset('exam-admin/js/participant.js') }}?v=1"></script>
@endpush
