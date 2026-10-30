# استقرار روی هاست اشتراکی (cPanel)

## ۱. پیش‌نیازها
- PHP **8.2 تا 8.5** (پیشنهادی: 8.5).
- اکستنشن‌ها: `mbstring`, `pdo_mysql`, `curl`, `openssl`, `zip`, `fileinfo`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`.
- MySQL 5.7+ / MariaDB 10.3+ با `utf8mb4`.
- دامنه با **HTTPS** (وب‌هوک بله فقط HTTPS روی پورت `443` یا `88`).
- دسترسی Cron و ترجیحاً SSH.

## ۲. دیتابیس
در cPanel ← MySQL Databases: دیتابیس و کاربر بسازید، همهٔ دسترسی‌ها را بدهید. Collation: `utf8mb4_unicode_ci`.

## ۳. آپلود و document root
- کل پروژه را **بیرون از** `public_html` آپلود کنید (مثلاً `~/itbbot`). پوشهٔ `vendor` را یا با `composer install --no-dev --optimize-autoloader` روی سرور بسازید یا از لوکال آپلود کنید.
- **بهترین حالت:** document root دامنه/ساب‌دامین را روی `~/itbbot/public` بگذارید (cPanel ← Domains).
- اگر ممکن نیست: محتوای `public/` را در `public_html` بگذارید و در `public_html/index.php` مسیرها را به `../itbbot/vendor/autoload.php` و `../itbbot/bootstrap/app.php` اصلاح کنید.
- `.env`، `storage/` و `vendor/` نباید از وب قابل دسترس باشند (با document root روی `public/` خودبه‌خود این‌طور است).
- دسترسی‌ها: `storage/` و `bootstrap/cache/` قابل نوشتن (۷۵۵/۷۷۵).

## ۴. `.env` محیط production
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://exam.example.ir
APP_TIMEZONE=Asia/Tehran

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=sync

BALE_BASE_URL=https://tapi.bale.ai
BALE_BOT_USERNAME=ITBbot
BALE_BOT_TOKEN=...            # فقط اینجا؛ هرگز در گیت
BALE_UPDATE_MODE=webhook
BALE_WEBHOOK_SECRET=...       # رشتهٔ تصادفی بلند (حداقل ۱۶، پیشنهاد ۴۸ کاراکتر)
BALE_MEMBERSHIP_MODE=getchatmember   # طبق نتیجهٔ docs/BALE-API-VERIFIED.md
BALE_JOIN_BUTTON=both

ANON_HMAC_SECRET=...          # همان مقدار قبلی؛ هرگز عوض نشود
ANON_TOKEN_TTL_MINUTES=20
ANON_MIN_RESPONSES=5
ANON_POOL_MODE=true           # پیشنهادی در production
EXAM_PUBLIC_URL=              # خالی = APP_URL
```
اولین بار: `php artisan key:generate` و `php artisan exam:env-setup --no-prompt` (سرّهای خالی را می‌سازد؛ مقدار موجود را عوض نمی‌کند).

## ۵. مایگریشن و ادمین
```bash
php artisan migrate --force
php artisan exam:make-admin
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## ۶. کرون‌جاب‌ها (cPanel ← Cron Jobs)
```
* * * * * cd ~/itbbot && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```
زمان‌بند هر دقیقه `exam:tick` (باز/بستن خودکار، یادآوری) و `exam:outbox` (ارسال مجدد پیام‌های گروه) را اجرا می‌کند.
اگر `QUEUE_CONNECTION=database` گذاشتید:
```
* * * * * cd ~/itbbot && /usr/local/bin/php artisan queue:work --stop-when-empty >> /dev/null 2>&1
```
(مسیر PHP را از پشتیبانی هاست بپرسید؛ مثلاً `/opt/cpanel/ea-php85/root/usr/bin/php`.)

## ۷. وب‌هوک
```bash
php artisan exam:webhook set     # https://<APP_URL>/api/bale/exam/<BALE_WEBHOOK_SECRET>
php artisan exam:webhook info
```
در production **`exam:poll` را اجرا نکنید** (polling و وب‌هوک هم‌زمان کار نمی‌کنند). برای بازگشت به polling: `exam:webhook delete`.

## ۸. حریم خصوصی روی هاست (مهم)
- **لاگ دسترسی وب‌سرور برای `/x` را خاموش کنید**؛ لاگ دسترسی زمان دقیق `/x/submit` و IP را دارد و می‌تواند با تطبیق زمان، ناشناسی را ضعیف کند. در cPanel ← Raw Access / Metrics معمولاً فقط کل لاگ قابل خاموش شدن است؛ اگر هاست اجازه می‌دهد در `.htaccess` (Apache 2.4 با `mod_setenvif`، در صورت پشتیبانی):
  ```apache
  SetEnvIf Request_URI "^/x(/|$)" dontlog
  ```
  و از پشتیبانی بخواهید `CustomLog ... env=!dontlog` را برای دامنه فعال کند. اگر ممکن نیست، دورهٔ نگه‌داری لاگ را حداقل کنید و `ANON_POOL_MODE=true` بگذارید.
- `APP_DEBUG=false`، `LOG_LEVEL=warning`.
- از `.env` (به‌ویژه `ANON_HMAC_SECRET` و `APP_KEY`) نسخهٔ پشتیبان امن جداگانه بگیرید؛ بدون `ANON_HMAC_SECRET` جلوگیری از رأی تکراری آزمون‌های جاری از کار می‌افتد و بدون `APP_KEY` پاسخ‌های داخل pool قابل بازیابی نیستند.
- اگر توکن ربات لو رفت: از `@botfather` در بله توکن جدید بگیرید، در `.env` بگذارید، `php artisan config:cache` و `exam:webhook set`.

## ۹. بررسی نهایی
- `https://<domain>/exam-admin/login` باز شود.
- `php artisan exam:bot-check` → `@ITBbot OK`.
- `php artisan exam:webhook info` → آدرس (با مسیر مخفی ماسک‌شده) و `pending_update_count` کم.
- یک آزمون آزمایشی طبق `docs/E2E-TEST.md`.
