@extends('ax::layouts.participant')
@section('title', 'آزمون ناشناس')

@section('content')
<div class="card result-box">
    <h1>⚠️</h1>
    <p>{{ $message }}</p>
    <p class="small muted mt"><a href="{{ route('ax.x.privacy') }}">پاسخ‌ها چطور ناشناس می‌مانند؟</a></p>
</div>
@endsection
