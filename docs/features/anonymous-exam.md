# آزمون و نظرسنجی ناشناس برای گروه‌های بله (Anonymous Exam)

## 📝 توضیحات

مدیر در پنل وب (`/exam-admin`) آزمون یا نظرسنجی تعریف می‌کند. ربات بله `@ITBbot` آن را در گروه اعلام می‌کند. **فقط اعضای همان گروه**، **هر نفر یک بار**، پاسخ می‌دهند و پاسخ‌ها **ناشناس** ذخیره می‌شوند: حتی مدیر سامانه نمی‌تواند بفهمد کدام عضو چه پاسخی داده است. مدیر زمان شروع و پایان را تعیین می‌کند و با «پایان آزمون» نتایج را می‌بندد و می‌بیند.

## 🎯 اهداف

- احراز عضویت گروه بدون ذخیرهٔ هویت (HMAC)
- جداسازی ساختاری «دنیای هویت» و «دنیای پاسخ» ([PRIVACY-MODEL.md](../PRIVACY-MODEL.md))
- پنل فارسی راست‌چین بدون وابستگی به CDN یا npm
- اجرای یک‌کلیکی روی ویندوز (`start.bat` / `stop.bat`) و استقرار روی هاست اشتراکی

## 📂 مسیر فایل‌ها

همهٔ کد ماژول در `app/Modules/AnonymousExam/`:

| بخش | مسیر |
|---|---|
| Service Provider | `AnonymousExamServiceProvider.php` (ثبت در `bootstrap/providers.php`) |
| کلاینت بله | `Bale/BaleApi.php` (interface)، `Bale/BaleClientImpl.php` |
| Models | `Models/*` — جدول‌ها با پیشوند `ax_` |
| Services | `Services/AccessService` (عضویت → توکن)، `TokenExchangeService`، `SubmissionService`، `AnswerValidator`، `ScoringService`، `ResultsService`، `ExamLifecycleService`، `ExamBuilderService`، `GroupService`، `OutboxService`، `BotUpdateHandler`، `MembershipVerifier(Impl)`، `PoolFlushService`، `TemplateService`، `SettingsService`، `AuditService`، `BotStatusService` |
| Controllers | `Http/Controllers/Admin/*`، `Http/Controllers/Participant/ParticipantController.php`، `Http/Controllers/WebhookController.php` |
| Form Requests | `Http/Requests/SaveExamRequest.php`، `LoginRequest.php` |
| Middleware | `AdminAuthenticate` (`ax.auth`)، `AdminRole` (`ax.role`)، `NoStoreHeaders` (`ax.nostore`) |
| Routes | `routes/web.php` (`/exam-admin/*`، `/x/*`)، `routes/api.php` (`/api/bale/exam/{secret}`) |
| Views | `resources/views/{layouts,admin,participant}` (namespace `ax::`) |
| ترجمه/متن ربات | `lang/fa/bot.php` (namespace `ax::bot`) — قابل ویرایش از پنل |
| Config | `config/anonymous_exam.php` (merge به `config('anonymous_exam')`) |
| Commands | `exam:make-admin`، `exam:bot-check`، `exam:poll`، `exam:webhook set\|info\|delete`، `exam:tick`، `exam:outbox`، `exam:flush-pool`، `exam:env-setup`، `exam:db-ensure`، `exam:has-admin` |
| Assets | `public/exam-admin/{css,js,fonts}` |
| Migrations | `database/migrations/anonymous_exam/` |
| Tests | `tests/Feature/AnonymousExam/` |

### Environment Variables
```env
BALE_BOT_TOKEN=                 # فقط در .env
BALE_BOT_USERNAME=ITBbot
BALE_BASE_URL=https://tapi.bale.ai
BALE_UPDATE_MODE=polling        # polling (لوکال) | webhook (هاست)
BALE_WEBHOOK_SECRET=            # مسیر مخفی وب‌هوک
BALE_MEMBERSHIP_MODE=getchatmember   # getchatmember | callback | manual
BALE_JOIN_BUTTON=both           # url | callback | both
ANON_HMAC_SECRET=               # یک‌بار ساخته می‌شود؛ هرگز عوض نشود
ANON_TOKEN_TTL_MINUTES=20
ANON_MIN_RESPONSES=5
ANON_POOL_MODE=false
EXAM_PUBLIC_URL=                # آدرس عمومی لینک‌ها (خالی = APP_URL)
EXAM_PORT=8000                  # پورت artisan serve در start.bat
APP_TIMEZONE=Asia/Tehran        # فقط نمایش؛ ذخیره همیشه UTC
```

## 🔄 Flow کاری

1. **ثبت گروه:** پنل ← «دریافت کد ثبت» (۱۰ دقیقه، یک‌بارمصرف) ← در گروه `/register CODE`. جایگزین: ورود دستی `chat_id` + پیام آزمایشی.
2. **ساخت آزمون:** سازندهٔ چندتبی (پایه، سؤال‌ها با کشیدن‌ورها کردن/تکثیر/ایمپورت متن و CSV، زمان‌بندی، ناشناسی و نتایج، پیش‌نمایش).
3. **انتشار:** «انتشار» (زمان‌بندی؛ زمان‌بند هر دقیقه باز می‌کند) یا «شروع فوری». ساختار آزمون قفل می‌شود؛ اعلام با دکمه‌های `url` (لینک عمیق) و `callback_data` در گروه.
4. **ورود عضو:** `/start exam_CODE` یا ضربه روی دکمهٔ گروه ← بررسی باز بودن آزمون ← بررسی عضویت ← `voter_hash` ← اگر قبلاً ثبت کرده «قبلاً شرکت کرده‌اید» وگرنه توکن جدید (توکن قبلی باطل) ← لینک در چت خصوصی.
5. **پاسخ:** `/x/{token}` ← مصرف توکن، سشن کوتاه‌عمر، ریدایرکت به `/x` ← فرم (یک‌صفحه‌ای/مرحله‌ای، پیش‌نویس محلی، شمارندهٔ زمان) ← ثبت در یک تراکنش.
6. **پایان:** خودکار در `ends_at` یا دکمهٔ «پایان آزمون» (تأیید با تایپ کد آزمون) ← توکن‌ها باطل، ثبت رد، نتایج باز، خلاصه در گروه.

## 🔧 نحوه استفاده

### لوکال (ویندوز)
```bat
start.bat
```
پیش‌نیازها را بررسی می‌کند، `.env` و سرّها را می‌سازد (توکن را محلی می‌پرسد)، MySQL، دیتابیس `bale_exam_bot`، `composer install`، مایگریشن، اولین ادمین، و پروسه‌های `serve`، `schedule:work`، `exam:poll` را بالا می‌آورد. توقف: `stop.bat`.

### هاست
[DEPLOY-SHARED-HOST.md](../DEPLOY-SHARED-HOST.md)

### ربات
- خصوصی: `/start`، `/start exam_<code>`، `/help`، `/exams`، `/privacy`، `/status <code>`
- گروه: `/register <code>`، `/help`
- مدیران (شناسهٔ بله در صفحهٔ «مدیران»): `/myexams`، `/close <code>`، `/results <code>`

## 📊 ساختار دیتابیس

`ax_admin_users`، `ax_bots`، `ax_settings`، `ax_message_templates`، `ax_audit_logs`، `ax_processed_updates`، `ax_groups`، `ax_registration_codes`، `ax_group_members` (فقط هش)، `ax_exams`، `ax_questions`، `ax_options`، **`ax_participations`**، **`ax_access_tokens`** (دنیای هویت)، **`ax_responses`**، **`ax_answers`**، `ax_response_pool` (دنیای پاسخ)، `ax_outbox`. جزئیات در مایگریشن‌ها و [PRIVACY-MODEL.md](../PRIVACY-MODEL.md).

## 🔐 Validation Rules

- همهٔ ورودی‌های پنل با Form Request؛ نقش‌ها (`owner/editor/viewer`) سمت سرور.
- پاسخ‌ها در سرور دوباره اعتبارسنجی می‌شوند: سؤال متعلق به همین آزمون، گزینهٔ متعلق به همین سؤال، طول متن، بازهٔ عدد/امتیاز، الزامی بودن.
- ثبت قبل از شروع، بعد از پایان، بعد از بسته شدن یا بعد از اتمام زمان شخصی همیشه در سرور رد می‌شود.

## 📝 Logging

- لاگ‌های ماژول فقط شامل کد خطا و `exam_id`/`outbox_id` هستند؛ هرگز شناسهٔ کاربر، توکن یا بدنهٔ درخواست.
- `LogHelper`/`BotLog` ریپو عمداً استفاده نمی‌شود (پیام‌های کاربران را ذخیره می‌کند).
- `ax_audit_logs`: فقط کارهای مدیران.

```php
Log::error('ax submit failed', ['exam_id' => $examId, 'error' => class_basename($e)]);
```

## 🧪 Testing

`php vendor/bin/phpunit tests/Feature/AnonymousExam` (دیتابیس `bale_exam_bot_test`). پوشش: عضو/غیرعضو، callback، یک‌بار ثبت، توکن یک‌بارمصرف/منقضی/وابسته به آزمون، بازهٔ زمانی، ناشناسی ساختاری و لاگ‌ها، قواعد حداقل پاسخ و ترتیب تصادفی، نمره‌دهی، بستن آزمون، pool، میدل‌ورهای `/x`، نقش‌ها، ایمپورت، تست دودی مسیرهای قدیمی. تست دستی: [E2E-TEST.md](../E2E-TEST.md).

## 🐛 Known Issues

- رفتار واقعی API بله هنوز با توکن واقعی تأیید نشده — [BALE-API-VERIFIED.md](../BALE-API-VERIFIED.md).
- خروجی XLSX و آپلود تصویر سؤال پیاده نشده ([DECISIONS.md](../DECISIONS.md)).

## 🔮 Future Improvements

- [ ] خروجی XLSX
- [ ] تصویر برای سؤال (بررسی MIME، نام تصادفی، خارج از مسیر اجرایی)
- [ ] مینی‌اپ بله (`web_app`) در صورت پشتیبانی

## 📚 مستندات مرتبط

- [README.md اصلی](../../README.md)
- [DECISIONS.md](../DECISIONS.md) · [PRIVACY-MODEL.md](../PRIVACY-MODEL.md) · [BALE-API-VERIFIED.md](../BALE-API-VERIFIED.md) · [DEPLOY-SHARED-HOST.md](../DEPLOY-SHARED-HOST.md) · [E2E-TEST.md](../E2E-TEST.md)

---

**آخرین بروزرسانی**: ۱۴۰۵/۰۸/۰۵
