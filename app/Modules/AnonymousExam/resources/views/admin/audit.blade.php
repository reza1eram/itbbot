@extends('ax::layouts.admin')
@section('title', 'گزارش عملیات مدیران')
@php($D = \App\Modules\AnonymousExam\Support\Display::class)

@section('content')
<div class="card">
    <p class="small muted">فقط عملیات مدیران ثبت می‌شود؛ هیچ رویدادی از شرکت‌کنندگان در این گزارش نیست.</p>
    @if($logs->isEmpty())
        <div class="empty">رکوردی وجود ندارد.</div>
    @else
    <div class="table-wrap">
        <table>
            <thead><tr><th>زمان</th><th>مدیر</th><th>عمل</th><th>موضوع</th><th>جزئیات</th></tr></thead>
            <tbody>
            @foreach($logs as $log)
                <tr>
                    <td class="small nowrap">{{ $D::dateTime($log->created_at) }}</td>
                    <td>{{ $log->admin?->username ?? 'سیستم' }}</td>
                    <td class="ltr small">{{ $log->action }}</td>
                    <td class="small">{{ $log->subject_type }} {{ $log->subject_id ? '#' . $log->subject_id : '' }}</td>
                    <td class="ltr small">{{ $log->meta ? json_encode($log->meta, JSON_UNESCAPED_UNICODE) : '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    {{ $logs->links('ax::admin.partials.pagination') }}
    @endif
</div>
@endsection
