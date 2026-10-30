@extends('ax::layouts.admin')
@section('title', 'پیش‌نمایش: ' . $exam->title)
@php($D = \App\Modules\AnonymousExam\Support\Display::class)

@section('content')
<div class="px">
    <div class="anon-note">🔒 پاسخ‌های شما ناشناس ثبت می‌شود.</div>
    <h2>{{ $exam->title }}</h2>
    @if($exam->description)<p class="muted">{{ $exam->description }}</p>@endif
    @forelse($questions as $i => $q)
        <div class="question">
            <div class="q-title">{{ $D::digits($i + 1) }}. {{ $q->text }} @if($q->is_required)<span class="req">*</span>@endif</div>
            @if($q->help_text)<div class="q-help">{{ $q->help_text }}</div>@endif
            @if($q->isChoice())
                @foreach($q->options as $o)
                    <label class="choice"><input type="{{ $q->type === 'multi' ? 'checkbox' : 'radio' }}" disabled> <span>{{ $o->text }} @if($exam->isQuiz() && $o->is_correct)<strong>✓</strong>@endif</span></label>
                @endforeach
            @elseif($q->type === 'rating')
                <div class="rating">@for($v = $q->ratingRange()[0]; $v <= $q->ratingRange()[1]; $v++)<label>{{ $D::digits($v) }}</label>@endfor</div>
            @elseif($q->type === 'long_text')
                <textarea disabled></textarea><div class="text-warning">متن خود را طوری ننویسید که هویتتان را لو بدهد.</div>
            @else
                <input type="text" disabled>
            @endif
        </div>
    @empty
        <div class="empty">سؤالی وجود ندارد.</div>
    @endforelse
    <a class="btn" href="{{ route('ax.admin.exams.show', $exam) }}">بازگشت</a>
</div>
@endsection
