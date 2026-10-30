<?php

use App\Modules\AnonymousExam\Http\Controllers\Admin;
use App\Modules\AnonymousExam\Http\Controllers\Participant\ParticipantController;
use Illuminate\Support\Facades\Route;

/*
| Participant routes (/x). NO logging / activity middleware may ever be attached here
| (enforced by tests/Feature/AnonymousExam/NoLoggingOnParticipantRoutesTest).
*/
Route::prefix('x')->middleware('ax.nostore')->name('ax.x.')->group(function () {
    Route::get('/', [ParticipantController::class, 'show'])->middleware('throttle:ax-participant')->name('show');
    Route::post('/submit', [ParticipantController::class, 'submit'])->middleware('throttle:ax-submit')->name('submit');
    Route::get('/done', [ParticipantController::class, 'done'])->name('done');
    Route::get('/privacy', [ParticipantController::class, 'privacy'])->name('privacy');
    Route::get('/{token}', [ParticipantController::class, 'exchange'])
        ->where('token', '[A-Za-z0-9_-]{20,100}')
        ->middleware('throttle:ax-exchange')
        ->name('exchange');
});

/*
| Admin panel (/exam-admin)
*/
Route::prefix('exam-admin')->middleware('ax.nostore')->name('ax.admin.')->group(function () {
    Route::get('/login', [Admin\AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [Admin\AuthController::class, 'login'])->middleware('throttle:ax-login')->name('login.submit');

    Route::middleware('ax.auth')->group(function () {
        Route::post('/logout', [Admin\AuthController::class, 'logout'])->name('logout');
        Route::get('/password', [Admin\AuthController::class, 'showPassword'])->name('password');
        Route::post('/password', [Admin\AuthController::class, 'updatePassword'])->name('password.update');

        Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

        // read-only pages
        Route::get('/bot', [Admin\BotController::class, 'index'])->name('bot');
        Route::get('/groups', [Admin\GroupController::class, 'index'])->name('groups');
        Route::get('/exams', [Admin\ExamController::class, 'index'])->name('exams');
        Route::get('/exams/{exam}', [Admin\ExamController::class, 'show'])->whereNumber('exam')->name('exams.show');
        Route::get('/exams/{exam}/preview', [Admin\ExamController::class, 'preview'])->whereNumber('exam')->name('exams.preview');
        Route::get('/exams/{exam}/results', [Admin\ResultsController::class, 'show'])->whereNumber('exam')->name('exams.results');
        Route::get('/exams/{exam}/export.csv', [Admin\ResultsController::class, 'csv'])->whereNumber('exam')->name('exams.csv');
        Route::get('/templates', [Admin\TemplateController::class, 'index'])->name('templates');
        Route::get('/settings', [Admin\SettingsController::class, 'index'])->name('settings');
        Route::get('/audit', [Admin\AdminUserController::class, 'audit'])->name('audit');

        // JSON API for dynamic pages
        Route::prefix('api')->name('api.')->group(function () {
            Route::get('/live', [Admin\DashboardController::class, 'live'])->name('live');
            Route::get('/exams/{exam}', [Admin\ExamController::class, 'apiShow'])->whereNumber('exam')->name('exams.show');
            Route::middleware('ax.role:edit')->group(function () {
                Route::post('/exams', [Admin\ExamController::class, 'apiStore'])->name('exams.store');
                Route::put('/exams/{exam}', [Admin\ExamController::class, 'apiUpdate'])->whereNumber('exam')->name('exams.update');
                Route::post('/import', [Admin\ExamController::class, 'apiImport'])->name('import');
            });
        });

        // mutations: owner / editor only
        Route::middleware('ax.role:edit')->group(function () {
            Route::post('/bot/webhook', [Admin\BotController::class, 'setWebhook'])->name('bot.webhook.set');
            Route::post('/bot/webhook/delete', [Admin\BotController::class, 'deleteWebhook'])->name('bot.webhook.delete');
            Route::post('/bot/check', [Admin\BotController::class, 'check'])->name('bot.check');
            Route::post('/bot/test', [Admin\BotController::class, 'testMessage'])->name('bot.test');
            Route::post('/bot/outbox/{message}/retry', [Admin\BotController::class, 'retryOutbox'])->whereNumber('message')->name('bot.outbox.retry');

            Route::post('/groups/code', [Admin\GroupController::class, 'code'])->name('groups.code');
            Route::post('/groups/manual', [Admin\GroupController::class, 'manual'])->name('groups.manual');
            Route::post('/groups/{group}/toggle', [Admin\GroupController::class, 'toggle'])->whereNumber('group')->name('groups.toggle');
            Route::post('/groups/{group}/test', [Admin\GroupController::class, 'test'])->whereNumber('group')->name('groups.test');
            Route::post('/groups/{group}/count', [Admin\GroupController::class, 'refreshCount'])->whereNumber('group')->name('groups.count');
            Route::post('/groups/{group}/members', [Admin\GroupController::class, 'members'])->whereNumber('group')->name('groups.members');

            Route::get('/exams/create', [Admin\ExamController::class, 'create'])->name('exams.create');
            Route::get('/exams/{exam}/edit', [Admin\ExamController::class, 'edit'])->whereNumber('exam')->name('exams.edit');
            Route::post('/exams/{exam}/duplicate', [Admin\ExamController::class, 'duplicate'])->whereNumber('exam')->name('exams.duplicate');
            Route::post('/exams/{exam}/archive', [Admin\ExamController::class, 'archive'])->whereNumber('exam')->name('exams.archive');
            Route::post('/exams/{exam}/publish', [Admin\ExamController::class, 'publish'])->whereNumber('exam')->name('exams.publish');
            Route::post('/exams/{exam}/open', [Admin\ExamController::class, 'openNow'])->whereNumber('exam')->name('exams.open');
            Route::post('/exams/{exam}/announce', [Admin\ExamController::class, 'announce'])->whereNumber('exam')->name('exams.announce');
            Route::post('/exams/{exam}/remind', [Admin\ExamController::class, 'remind'])->whereNumber('exam')->name('exams.remind');
            Route::post('/exams/{exam}/close', [Admin\ExamController::class, 'close'])->whereNumber('exam')->name('exams.close');
            Route::post('/exams/{exam}/flush-pool', [Admin\ResultsController::class, 'flushPool'])->whereNumber('exam')->name('exams.flush');

            Route::post('/templates', [Admin\TemplateController::class, 'update'])->name('templates.update');
            Route::post('/settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
        });

        Route::middleware('ax.role:owner')->group(function () {
            Route::get('/admins', [Admin\AdminUserController::class, 'index'])->name('admins');
            Route::post('/admins', [Admin\AdminUserController::class, 'store'])->name('admins.store');
            Route::post('/admins/{admin}', [Admin\AdminUserController::class, 'update'])->whereNumber('admin')->name('admins.update');
        });
    });
});
