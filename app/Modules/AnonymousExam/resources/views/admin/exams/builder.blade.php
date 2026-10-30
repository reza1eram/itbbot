@extends('ax::layouts.admin')
@section('title', $exam ? 'ویرایش آزمون' : 'آزمون جدید')

@section('content')
<script type="application/json" id="builder-config">{!! json_encode([
    'examId' => $exam?->id,
    'loadUrl' => $exam ? route('ax.admin.api.exams.show', $exam) : null,
    'storeUrl' => route('ax.admin.api.exams.store'),
    'updateUrl' => $exam ? route('ax.admin.api.exams.update', $exam) : null,
    'importUrl' => route('ax.admin.api.import'),
    'showUrl' => $exam ? route('ax.admin.exams.show', $exam) : null,
    'previewUrl' => $exam ? route('ax.admin.exams.preview', $exam) : null,
    'groups' => $groups->map(fn ($g) => ['id' => $g->id, 'title' => $g->title ?? (string) $g->chat_id])->values(),
    'defaults' => $defaults,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

<div class="card" id="builder">
    <div id="lock-note" class="lock-note hidden">این آزمون باز شده است؛ فقط عنوان، توضیح، زمان پایان، یادآوری‌ها و اعلام پایان قابل تغییرند. هر تغییر در گزارش عملیات ثبت می‌شود.</div>
    <div id="builder-alert" class="alert hidden"></div>

    <div class="tabs" role="tablist">
        <button type="button" class="active" data-tab="basic">اطلاعات پایه</button>
        <button type="button" data-tab="questions">سؤال‌ها <span class="badge" id="q-count">۰</span></button>
        <button type="button" data-tab="schedule">زمان‌بندی</button>
        <button type="button" data-tab="privacy">ناشناسی و نتایج</button>
        <button type="button" data-tab="preview">پیش‌نمایش و انتشار</button>
    </div>

    <section class="tab-panel active" data-panel="basic">
        <div class="field"><label for="f-title">عنوان *</label><input type="text" id="f-title" data-field="title" maxlength="255" data-safe></div>
        <div class="field"><label for="f-description">توضیح (در اعلام گروه و بالای فرم نمایش داده می‌شود)</label><textarea id="f-description" data-field="description" maxlength="3000" data-safe></textarea></div>
        <div class="row">
            <div class="field"><label for="f-mode">نوع</label>
                <select id="f-mode" data-field="mode"><option value="survey">نظرسنجی (بدون نمره)</option><option value="quiz">آزمون نمره‌دار</option></select></div>
            <div class="field"><label for="f-group">گروه هدف</label>
                <select id="f-group" data-field="group_id"><option value="">— انتخاب گروه —</option></select>
                <div class="hint">فقط اعضای همین گروه می‌توانند پاسخ دهند.</div></div>
        </div>
    </section>

    <section class="tab-panel" data-panel="questions">
        <div class="actions" id="q-toolbar">
            <select id="new-q-type">
                <option value="single">تک‌گزینه‌ای</option><option value="multi">چندگزینه‌ای</option><option value="true_false">درست / نادرست</option>
                <option value="short_text">متن کوتاه</option><option value="long_text">متن بلند</option><option value="rating">امتیازدهی</option><option value="number">عدد</option>
            </select>
            <button type="button" class="btn btn-primary" id="add-q">+ افزودن سؤال</button>
            <button type="button" class="btn" id="toggle-import">ایمپورت گروهی</button>
        </div>
        <div id="import-box" class="card hidden mt">
            <div class="row">
                <div class="field"><label>قالب</label><select id="import-format"><option value="text">متن ساده</option><option value="csv">CSV</option></select></div>
            </div>
            <textarea id="import-raw" class="ltr" rows="8" placeholder="متن سؤال اول؟&#10;- گزینهٔ نادرست&#10;* گزینهٔ صحیح&#10;&#10;[long] سؤال متنی دوم"></textarea>
            <p class="hint small muted">متن ساده: هر سؤال یک بلوک؛ گزینه‌ها با «-» و گزینهٔ صحیح با «*». برچسب نوع اختیاری: [single] [multi] [tf] [short] [long] [rating] [number].<br>CSV: <span class="ltr">type,text,options(|),correct(1|3),points,required</span></p>
            <button type="button" class="btn" id="run-import">افزودن به سؤال‌ها</button>
        </div>
        <div id="questions" class="mt"></div>
        <div id="q-empty" class="empty">هنوز سؤالی اضافه نشده است.</div>
    </section>

    <section class="tab-panel" data-panel="schedule">
        <div class="row">
            <div class="field"><label for="f-starts">زمان شروع (به وقت تهران)</label><input type="datetime-local" id="f-starts" data-field="starts_at">
                <div class="hint">خالی = با دکمهٔ «انتشار» یا «شروع فوری» باز می‌شود.</div></div>
            <div class="field"><label for="f-ends">زمان پایان (به وقت تهران) *</label><input type="datetime-local" id="f-ends" data-field="ends_at" data-safe></div>
        </div>
        <div class="row">
            <div class="field"><label for="f-duration">محدودیت زمان هر شرکت‌کننده (دقیقه)</label><input type="number" id="f-duration" data-field="duration_minutes" min="1" max="1440">
                <div class="hint">خالی = بدون محدودیت. شمارش از لحظهٔ باز کردن لینک.</div></div>
            <div class="field"><label for="f-reminders">یادآوری قبل از پایان (دقیقه، با ویرگول)</label><input type="text" id="f-reminders" data-field="reminders" class="ltr" placeholder="60, 10" data-safe>
                <div class="hint">خالی = بدون یادآوری.</div></div>
        </div>
        <label class="check"><input type="checkbox" data-field="announce_on_open"> اعلام در گروه هنگام شروع</label>
        <label class="check"><input type="checkbox" data-field="announce_on_close" data-safe> اعلام خلاصهٔ نتیجه در گروه هنگام پایان</label>
        <div class="field mt"><label for="f-display">نمایش فرم</label>
            <select id="f-display" data-field="display_mode"><option value="single_page">همهٔ سؤال‌ها در یک صفحه</option><option value="stepwise">مرحله‌ای (یک سؤال در هر صفحه)</option></select></div>
        <label class="check"><input type="checkbox" data-field="shuffle_questions"> ترتیب تصادفی سؤال‌ها</label>
        <label class="check"><input type="checkbox" data-field="shuffle_options"> ترتیب تصادفی گزینه‌ها</label>
    </section>

    <section class="tab-panel" data-panel="privacy">
        <div class="alert alert-info small">پاسخ‌ها همیشه بدون هیچ پیوندی به هویت ذخیره می‌شوند. تنظیمات زیر برای جلوگیری از «حدس هویت» در گروه‌های کوچک است.</div>
        <div class="row">
            <div class="field"><label for="f-min">حداقل تعداد پاسخ برای نمایش نتایج تفکیکی</label><input type="number" id="f-min" data-field="min_responses_to_show" min="1" max="1000">
                <div class="hint">تا رسیدن به این تعداد، توزیع گزینه‌ها، پاسخ‌های متنی و آمار نمره نمایش داده نمی‌شود.</div></div>
            <div class="field"><label for="f-visibility">نمایش نتایج در پنل</label>
                <select id="f-visibility" data-field="results_visibility"><option value="after_close">فقط بعد از پایان آزمون (پیشنهادی)</option><option value="live">نمایش زنده (با رعایت حداقل تعداد)</option></select></div>
        </div>
        <div class="field"><label for="f-showres">نمایش به شرکت‌کننده پس از ثبت (فقط آزمون نمره‌دار)</label>
            <select id="f-showres" data-field="show_result_to_voter"><option value="none">هیچ</option><option value="score">فقط نمره</option><option value="score_and_answers">نمره و پاسخ‌های صحیح</option></select></div>
    </section>

    <section class="tab-panel" data-panel="preview">
        <div id="preview-area"></div>
        <p class="muted small mt">پس از ذخیره، انتشار و شروع آزمون از صفحهٔ «اجرای آزمون» انجام می‌شود.</p>
    </section>

    <div class="sticky-bar">
        <button type="button" class="btn btn-primary" id="save-btn">ذخیره</button>
        <a class="btn hidden" id="run-link" href="#">اجرای آزمون ←</a>
        <span class="muted small" id="save-state"></span>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('exam-admin/js/builder.js') }}?v=1"></script>
@endpush
