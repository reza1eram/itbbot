# ITBbot

Anonymous surveys and exams that run inside a Bale group chat. Only members of the group can answer, each person once, and the answers cannot be traced back to anyone, not even by the system admin.

Stack: Laravel 12, PHP 8.2+, MySQL, Blade and plain JavaScript, Bale Bot API.

## The problem

An HR team needed honest feedback from staff, and three things got in the way:

- People are not candid if they suspect their answers can be traced. In most survey tools, "anonymous" only means the admin promises not to look.
- Answers had to come only from the people in the staff group, one answer per person, with no link that could be forwarded to outsiders.
- Asking every employee to install another app or create another login was a non-starter. Everyone already used a Bale group (Bale is a popular Iranian messenger).

## The solution

A Laravel app plus a Bale bot that works inside the group people already use.

1. An admin builds a survey or exam in a web panel and publishes it.
2. The bot announces it in the group with a button.
3. A member taps the button. The bot checks that they really are in the group and sends a one-time link in a private chat.
4. The member fills in a short web form. The answer is stored in a way that cannot be tied back to them.
5. When the survey closes, the bot posts a summary in the group. Detailed results appear only after enough people have answered.

No new app and no new login for staff.

## How anonymity works

Identity and answers live in two separate worlds that share no key:

```
identity (bot side)                     answers (web side)
ax_participations                       ax_responses
  voter_hash = HMAC(user|exam, key)       random UUID, time rounded to the hour
  state, day only                       ax_answers
ax_access_tokens                          response_id, question_id, value
  sha256 of the token only
```

- The HMAC key exists only in `.env`. Without it, a list of member IDs is not enough to rebuild the hashes.
- The module tables have no user ID, IP address or user agent column. An automated test checks this.
- Access links are random, single use, expire after 20 minutes (configurable), and only their sha256 is stored. The token is swapped for a session and removed from the URL right away.
- Results stay hidden until a minimum number of answers is reached (default 5). Free-text answers are shown in random order without timestamps.
- Optional pool mode holds encrypted answers and releases them in shuffled batches.
- The submit step and the "already answered" mark happen in one database transaction with a row lock, so double submission is not possible.

Honest limits: someone who has the database, the `.env` file and complete web-server logs may still be able to correlate answers by timing. The threat table in [docs/PRIVACY-MODEL.md](docs/PRIVACY-MODEL.md) lists what is solved and what is not.

## Features

- Question types: single choice, multiple choice, short text, long text, number, rating, with optional scoring for exams.
- Scheduled or immediate start, automatic or manual close (manual close is confirmed by typing the exam code).
- Persian right-to-left admin panel with owner, editor and viewer roles and an audit log of admin actions. No npm and no CDN.
- Bale integration by long polling (local) or webhook (hosting). Membership is verified with `getChatMember`, a callback button, or a hashed manual list.
- Bot messages are editable from the panel.
- Runs on cPanel shared hosting or locally on Windows with one click.

## Run it

Windows (Laragon or XAMPP with MySQL running):

```bat
start.bat
```

It checks prerequisites, creates `.env` and the secret keys, asks for the bot token locally, prepares the database and the first admin, then starts the server, the scheduler and the bot update poller. Stop everything with `stop.bat`.

Shared hosting: see [docs/DEPLOY-SHARED-HOST.md](docs/DEPLOY-SHARED-HOST.md).

Configuration starts from `.env.example`. The bot token and the HMAC secret live only in `.env`, which is git-ignored. Never commit real values.

## Tests

```bat
php vendor\bin\phpunit
```

A MySQL database named `bale_exam_bot_test` is required. The 40+ feature tests cover membership checks, one answer per person, token expiry, structural anonymity, results rules and scoring.

## Documentation

The detailed docs are written in Persian.

- [Module guide](docs/features/anonymous-exam.md)
- [Privacy model](docs/PRIVACY-MODEL.md)
- [Shared hosting deployment (cPanel)](docs/DEPLOY-SHARED-HOST.md)
- [End-to-end manual test](docs/E2E-TEST.md)
- [Bale API behaviour checks](docs/BALE-API-VERIFIED.md)
- [Design decisions](docs/DECISIONS.md)

## Acknowledgements

Started from the Laravel scaffold of [saber13812002/bot-generator-bale-telegram-laravel-10](https://github.com/saber13812002/bot-generator-bale-telegram-laravel-10). This repository contains only the standalone anonymous exam module and the Laravel 12 skeleton it runs on.

## خلاصه به فارسی

**مسئله:** تیم منابع انسانی یک سازمان به نظرسنجی‌ای نیاز داشت که واقعاً ناشناس باشد، فقط اعضای گروه کارکنان در آن شرکت کنند و هر نفر یک بار پاسخ بدهد. ابزارهای معمول یا اپ و ورود جدید می‌خواستند یا ناشناسی‌شان فقط یک قول بود.

**راه‌حل:** یک ربات بله که آزمون یا نظرسنجی را داخل همان گروه کارکنان اعلام می‌کند. عضویت فرد از طریق API بله بررسی می‌شود، یک لینک یک‌بارمصرف در چت خصوصی می‌رسد و پاسخ‌ها طوری ذخیره می‌شوند که هیچ ارتباطی با هویت نداشته باشند، حتی برای مدیر سامانه. پنل مدیریت فارسی و راست‌چین است و روی هاست اشتراکی یا ویندوز اجرا می‌شود.
