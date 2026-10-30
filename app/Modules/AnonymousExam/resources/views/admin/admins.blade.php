@extends('ax::layouts.admin')
@section('title', 'مدیران')
@php($D = \App\Modules\AnonymousExam\Support\Display::class)
@php($roles = ['owner' => 'مالک', 'editor' => 'ویرایشگر', 'viewer' => 'بیننده (فقط خواندن)'])

@section('content')
<div class="card">
    <h2>مدیران پنل</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>نام</th><th>نام کاربری</th><th>نقش / وضعیت / شناسهٔ بله / رمز جدید</th><th>آخرین ورود</th></tr></thead>
            <tbody>
            @foreach($admins as $a)
                <tr>
                    <td>{{ $a->name }}</td>
                    <td class="ltr">{{ $a->username }}</td>
                    <td>
                        <form method="post" action="{{ route('ax.admin.admins.update', $a->id) }}" class="row">
                            @csrf
                            <select name="role">@foreach($roles as $k => $l)<option value="{{ $k }}" @selected($a->role === $k)>{{ $l }}</option>@endforeach</select>
                            <label class="check"><input type="checkbox" name="is_active" value="1" @checked($a->is_active)> فعال</label>
                            <input type="text" name="bale_admin_chat_id" value="{{ $a->bale_admin_chat_id }}" class="ltr" placeholder="chat id بله (اختیاری)">
                            <input type="password" name="password" class="ltr" placeholder="رمز جدید (اختیاری)" autocomplete="new-password">
                            <div><button class="btn btn-sm">ذخیره</button></div>
                        </form>
                    </td>
                    <td class="small nowrap">{{ $D::dateTime($a->last_login_at) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p class="small muted">شناسهٔ بله برای فرمان‌های مدیریتی ربات (/myexams، /close، /results) در چت خصوصی است.</p>
</div>

<form method="post" action="{{ route('ax.admin.admins.store') }}" class="card">
    @csrf
    <h2>افزودن مدیر</h2>
    <div class="row">
        <div class="field"><label>نام</label><input type="text" name="name" required maxlength="100"></div>
        <div class="field"><label>نام کاربری</label><input type="text" name="username" class="ltr" required maxlength="60"></div>
        <div class="field"><label>رمز (حداقل ۱۰ کاراکتر)</label><input type="password" name="password" class="ltr" required minlength="10" autocomplete="new-password"></div>
        <div class="field"><label>نقش</label><select name="role">@foreach($roles as $k => $l)<option value="{{ $k }}" @selected($k === 'editor')>{{ $l }}</option>@endforeach</select></div>
    </div>
    <button class="btn btn-primary">افزودن</button>
</form>
@endsection
