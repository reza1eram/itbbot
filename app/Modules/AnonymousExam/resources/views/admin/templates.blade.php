@extends('ax::layouts.admin')
@section('title', 'قالب پیام‌های ربات')
@php($canEdit = auth('ax_admin')->user()->canEdit())

@section('content')
<div class="alert alert-info small">متن خالی = بازگشت به متن پیش‌فرض. برجسته در بله با <code>*متن*</code> و کج با <code>_متن_</code> (با فاصله دور علامت‌ها). متغیرها مانند <code>:title</code> هنگام ارسال جایگزین می‌شوند.</div>
<form method="post" action="{{ route('ax.admin.templates.update') }}">
    @csrf
    @foreach($templates as $key => $t)
        <div class="card">
            <div class="card-head">
                <h3 class="ltr">{{ $key }}</h3>
                <div class="small muted">@if($t['override'])<span class="badge badge-scheduled">ویرایش‌شده</span>@endif
                    @if($t['variables']) متغیرها: <span class="ltr">{{ implode(' ', $t['variables']) }}</span>@endif</div>
            </div>
            <textarea name="templates[{{ $key }}]" rows="{{ min(10, max(2, substr_count($t['override'] ?? $t['default'], "\n") + 2)) }}" @disabled(!$canEdit) placeholder="{{ $t['default'] }}">{{ $t['override'] ?? $t['default'] }}</textarea>
        </div>
    @endforeach
    @if($canEdit)<div class="card"><button class="btn btn-primary">ذخیرهٔ همه</button></div>@endif
</form>
@endsection
