@extends('ax::layouts.participant')
@section('title', 'حریم خصوصی')

@section('content')
<div class="card">
    <h1>🔒 پاسخ‌های شما چطور ناشناس می‌ماند؟</h1>
    <p class="pre">{{ $text }}</p>
</div>
<div class="card">
    <h2>چه چیزی تضمین می‌شود و چه چیزی نه</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>تهدید</th><th>وضعیت</th></tr></thead>
            <tbody>
                <tr><td>مدیر پنل بخواهد بفهمد چه کسی چه گفته</td><td><span class="badge badge-ok">حل‌شده</span> هیچ رابطه‌ای بین هویت و پاسخ ذخیره نمی‌شود.</td></tr>
                <tr><td>کسی که فقط به دیتابیس دسترسی دارد</td><td><span class="badge badge-ok">حل‌شده</span> اثر انگشت هویت بدون کلید سرور برگشت‌پذیر نیست و پاسخ‌ها به آن لینک ندارند.</td></tr>
                <tr><td>کسی که به دیتابیس، کلیدهای سرور و لاگ کامل وب‌سرور دسترسی دارد</td><td><span class="badge badge-pending">تضمین مطلق نیست</span> ممکن است با تطبیق زمان‌ها حدس بزند. زمان‌ها گرد می‌شوند و IP ذخیره نمی‌شود تا این حدس دشوار شود.</td></tr>
                <tr><td>متن آزادی که خودتان هویتتان را در آن بنویسید</td><td>به عهدهٔ شما — در پاسخ‌های متنی مراقب باشید.</td></tr>
                <tr><td>گروه خیلی کوچک</td><td>نتایج تفکیکی تا رسیدن به حداقل تعداد پاسخ نمایش داده نمی‌شود.</td></tr>
            </tbody>
        </table>
    </div>
</div>
@endsection
