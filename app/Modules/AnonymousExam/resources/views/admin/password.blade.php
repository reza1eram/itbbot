@extends('ax::layouts.admin')
@section('title', 'تغییر رمز')

@section('content')
<form method="post" action="{{ route('ax.admin.password.update') }}" class="card auth-card">
    @csrf
    <div class="field"><label>رمز فعلی</label><input type="password" name="current_password" class="ltr" required autocomplete="current-password"></div>
    <div class="field"><label>رمز جدید (حداقل ۱۰ کاراکتر)</label><input type="password" name="password" class="ltr" required minlength="10" autocomplete="new-password"></div>
    <div class="field"><label>تکرار رمز جدید</label><input type="password" name="password_confirmation" class="ltr" required autocomplete="new-password"></div>
    <button class="btn btn-primary">تغییر رمز</button>
</form>
@endsection
