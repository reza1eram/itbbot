@extends('ax::layouts.admin')
@section('title', 'آزمون‌ها')
@php($D = \App\Modules\AnonymousExam\Support\Display::class)
@php($canEdit = auth('ax_admin')->user()->canEdit())
@php($filters = ['' => 'همه (جز بایگانی)', 'draft' => 'پیش‌نویس', 'scheduled' => 'زمان‌بندی‌شده', 'open' => 'باز', 'closed' => 'بسته', 'archived' => 'بایگانی'])

@section('content')
<div class="card">
    <div class="card-head">
        <form method="get" class="row" action="{{ route('ax.admin.exams') }}">
            @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
            <input type="search" name="q" value="{{ $q }}" placeholder="جستجو در عنوان یا کد">
            <div><button class="btn">جستجو</button></div>
        </form>
        @if($canEdit)<a class="btn btn-primary" href="{{ route('ax.admin.exams.create') }}">+ آزمون جدید</a>@endif
    </div>
    <div class="filters">
        @foreach($filters as $key => $label)
            <a href="{{ route('ax.admin.exams', array_filter(['status' => $key, 'q' => $q])) }}" @class(['active' => (string) $status === (string) $key])>{{ $label }}</a>
        @endforeach
    </div>

    @if($exams->isEmpty())
        <div class="empty">آزمونی یافت نشد.</div>
    @else
    <div class="table-wrap">
        <table>
            <thead><tr><th>عنوان</th><th>نوع</th><th>گروه</th><th>وضعیت</th><th>شروع</th><th>پایان</th><th>کد</th><th></th></tr></thead>
            <tbody>
            @foreach($exams as $exam)
                <tr>
                    <td><a href="{{ route('ax.admin.exams.show', $exam) }}">{{ $exam->title }}</a></td>
                    <td>{{ $exam->mode === 'quiz' ? 'آزمون' : 'نظرسنجی' }}</td>
                    <td>{{ $exam->group?->title ?? '—' }}</td>
                    <td>@include('ax::admin.partials.status', ['status' => $exam->status])</td>
                    <td class="small nowrap">{{ $D::dateTime($exam->starts_at) }}</td>
                    <td class="small nowrap">{{ $D::dateTime($exam->ends_at) }}</td>
                    <td class="ltr small">{{ $exam->public_code }}</td>
                    <td>
                        <div class="actions">
                            @if($canEdit && $exam->status !== 'archived')<a class="btn btn-sm" href="{{ route('ax.admin.exams.edit', $exam) }}">ویرایش</a>@endif
                            @if($canEdit)
                                <form method="post" action="{{ route('ax.admin.exams.duplicate', $exam) }}" class="inline">@csrf<button class="btn btn-sm">تکثیر</button></form>
                                @if(in_array($exam->status, ['draft', 'closed', 'scheduled']))
                                    <form method="post" action="{{ route('ax.admin.exams.archive', $exam) }}" class="inline" data-confirm="آزمون بایگانی شود؟">@csrf<button class="btn btn-sm btn-ghost">بایگانی</button></form>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    {{ $exams->links('ax::admin.partials.pagination') }}
    @endif
</div>
@endsection
