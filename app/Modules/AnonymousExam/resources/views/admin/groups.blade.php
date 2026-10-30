@extends('ax::layouts.admin')
@section('title', 'گروه‌ها')
@php($D = \App\Modules\AnonymousExam\Support\Display::class)
@php($canEdit = auth('ax_admin')->user()->canEdit())

@section('content')
@if($canEdit)
<div class="grid grid-2">
    <div class="card">
        <h2>افزودن گروه با کد ثبت</h2>
        <ol class="small">
            <li>ربات <span class="ltr">&#64;{{ $botUsername }}</span> را به گروه اضافه کنید (ترجیحاً به‌عنوان ادمین گروه).</li>
            <li>دکمهٔ زیر را بزنید و کد یک‌بارمصرف بگیرید (۱۰ دقیقه اعتبار).</li>
            <li>داخل گروه بنویسید: <code>/register کد</code></li>
        </ol>
        @if(session('registration_code'))
            <div class="code-box">{{ session('registration_code') }}</div>
            <p class="small">در گروه بفرستید: <code class="ltr">/register {{ session('registration_code') }}</code></p>
        @endif
        <form method="post" action="{{ route('ax.admin.groups.code') }}">@csrf<button class="btn btn-primary">دریافت کد ثبت</button></form>
    </div>
    <div class="card">
        <h2>افزودن دستی (راه جایگزین)</h2>
        <p class="small muted">اگر ربات فرمان /register را در گروه نمی‌بیند، شناسهٔ عددی گروه را وارد کنید. ربات یک پیام آزمایشی در گروه می‌فرستد.</p>
        <form method="post" action="{{ route('ax.admin.groups.manual') }}">
            @csrf
            <div class="field"><label>chat_id گروه</label><input type="text" name="chat_id" class="ltr" placeholder="-1001234567890" required></div>
            <button class="btn">افزودن و ارسال پیام آزمایشی</button>
        </form>
    </div>
</div>
@endif

<div class="card">
    <h2>گروه‌های ثبت‌شده</h2>
    @if($groups->isEmpty())
        <div class="empty">هنوز گروهی ثبت نشده است.</div>
    @else
    <div class="table-wrap">
        <table>
            <thead><tr><th>عنوان</th><th>chat_id</th><th>اعضا</th><th>آزمون‌ها</th><th>وضعیت</th><th>ثبت</th><th></th></tr></thead>
            <tbody>
            @foreach($groups as $g)
                <tr>
                    <td>{{ $g->title ?? '—' }}</td>
                    <td class="ltr small">{{ $g->chat_id }}</td>
                    <td>{{ $g->members_count !== null ? $D::digits($g->members_count) : '—' }}</td>
                    <td>{{ $D::digits($g->exams_count) }}</td>
                    <td>@if($g->is_active)<span class="badge badge-ok">فعال</span>@else<span class="badge">غیرفعال</span>@endif</td>
                    <td class="small nowrap">{{ $D::date($g->registered_at) }}</td>
                    <td>
                        @if($canEdit)
                        <div class="actions">
                            <form method="post" action="{{ route('ax.admin.groups.count', $g->id) }}" class="inline">@csrf<button class="btn btn-sm">تعداد اعضا</button></form>
                            <form method="post" action="{{ route('ax.admin.groups.test', $g->id) }}" class="inline">@csrf<button class="btn btn-sm">پیام آزمایشی</button></form>
                            <form method="post" action="{{ route('ax.admin.groups.toggle', $g->id) }}" class="inline" data-confirm="وضعیت گروه تغییر کند؟">@csrf<button class="btn btn-sm">{{ $g->is_active ? 'غیرفعال' : 'فعال' }} کن</button></form>
                        </div>
                        @endif
                    </td>
                </tr>
                @if($membershipMode === 'manual' && $canEdit)
                <tr>
                    <td colspan="7">
                        <form method="post" action="{{ route('ax.admin.groups.members', $g->id) }}">
                            @csrf
                            <label class="small">فهرست شناسهٔ عددی اعضا (حالت manual) — اکنون {{ $D::digits($g->hashed_members_count) }} عضو ثبت شده است</label>
                            <textarea name="ids" class="ltr" placeholder="12345, 67890 ..."></textarea>
                            <p class="small muted">شناسه‌ها فوراً با HMAC هش می‌شوند و نسخهٔ خام نگه‌داری نمی‌شود. ارسال فهرست جدید جایگزین فهرست قبلی می‌شود.</p>
                            <button class="btn btn-sm">جایگزینی فهرست</button>
                        </form>
                    </td>
                </tr>
                @endif
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
