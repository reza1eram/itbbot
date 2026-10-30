<?php

namespace App\Modules\AnonymousExam;

use App\Modules\AnonymousExam\Bale\BaleApi;
use App\Modules\AnonymousExam\Bale\BaleClientImpl;
use App\Modules\AnonymousExam\Console\Commands;
use App\Modules\AnonymousExam\Http\Middleware\AdminRole;
use App\Modules\AnonymousExam\Http\Middleware\NoStoreHeaders;
use App\Modules\AnonymousExam\Models\AdminUser;
use App\Modules\AnonymousExam\Services\MembershipVerifier;
use App\Modules\AnonymousExam\Services\MembershipVerifierImpl;
use App\Modules\AnonymousExam\Services\OutboxService;
use App\Modules\AnonymousExam\Services\PoolFlushService;
use App\Modules\AnonymousExam\Services\SubmissionService;
use App\Modules\AnonymousExam\Support\VoterHasher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Self-contained anonymous exam / survey module for Bale groups (@ITBbot).
 * Everything lives under app/Modules/AnonymousExam, database/migrations/anonymous_exam, public/exam-admin
 * and tables prefixed with ax_. Existing bots of the repo are not touched.
 */
class AnonymousExamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/anonymous_exam.php', 'anonymous_exam');

        $this->app->singleton(BaleApi::class, fn () => new BaleClientImpl(
            config('anonymous_exam.bale.token'),
            (string) config('anonymous_exam.bale.base_url'),
            (int) config('anonymous_exam.bale.timeout'),
            (int) config('anonymous_exam.bale.retries'),
        ));

        $this->app->singleton(VoterHasher::class, fn () => new VoterHasher(config('anonymous_exam.anon.hmac_secret')));

        $this->app->bind(MembershipVerifier::class, fn ($app) => new MembershipVerifierImpl(
            $app->make(BaleApi::class),
            $app->make(VoterHasher::class),
            (string) config('anonymous_exam.bale.membership_mode', 'getchatmember'),
            (int) config('anonymous_exam.bale.membership_cache_seconds', 60),
        ));

        $this->app->bind(OutboxService::class, fn ($app) => new OutboxService(
            $app->make(BaleApi::class),
            (int) config('anonymous_exam.bale.send_per_second', 20),
        ));

        $this->app->bind(PoolFlushService::class, fn () => new PoolFlushService((int) config('anonymous_exam.anon.pool_flush_min_batch', 3)));

        $this->app->bind(SubmissionService::class, fn ($app) => new SubmissionService(
            $app->make(Services\AnswerValidator::class),
            $app->make(Services\ScoringService::class),
            (bool) config('anonymous_exam.anon.pool_mode', false),
        ));

        // Separate guard for panel admins (independent of the repo's users and of Nova)
        config([
            'auth.guards.ax_admin' => ['driver' => 'session', 'provider' => 'ax_admins'],
            'auth.providers.ax_admins' => ['driver' => 'eloquent', 'model' => AdminUser::class],
        ]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/anonymous_exam'));
        $this->loadTranslationsFrom(__DIR__ . '/lang', 'ax');
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'ax');

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('ax.auth', Http\Middleware\AdminAuthenticate::class);
        $router->aliasMiddleware('ax.role', AdminRole::class);
        $router->aliasMiddleware('ax.nostore', NoStoreHeaders::class);

        $this->configureRateLimiting();

        Route::middleware('web')->group(__DIR__ . '/routes/web.php');
        // Not in the "api" group: its 60/min-per-IP throttle is too tight for Bale's webhook deliveries
        Route::prefix('api')->group(__DIR__ . '/routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\MakeAdminCommand::class,
                Commands\BotCheckCommand::class,
                Commands\PollCommand::class,
                Commands\WebhookCommand::class,
                Commands\TickCommand::class,
                Commands\OutboxCommand::class,
                Commands\EnvSetupCommand::class,
                Commands\FlushPoolCommand::class,
                Commands\HasAdminCommand::class,
                Commands\DbEnsureCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('exam:tick')->everyMinute()->withoutOverlapping(5);
            $schedule->command('exam:outbox')->everyMinute()->withoutOverlapping(5);
            $schedule->call(fn () => \Illuminate\Support\Facades\DB::table('ax_processed_updates')
                ->where('day', '<', now()->subDays(3)->toDateString())->delete())->daily()->name('ax-prune-updates');
        });

        // Backup scheduler: shared-host cron jobs fail silently far too often. After an HTTP response has been
        // sent, run the minute task at most once per minute (panel visits and webhook calls keep it going).
        if (!$this->app->runningInConsole() && config('anonymous_exam.lazy_scheduler', true)) {
            $this->app->terminating(function () {
                try {
                    if (Cache::add('ax:lazy-tick', 1, 60)) {
                        app(Services\ExamLifecycleService::class)->tick();
                        app(OutboxService::class)->dispatchDue(10);
                        Services\SchedulerHealth::markTick('web');
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('ax lazy tick failed', ['error' => class_basename($e)]);
                }
            });
        }
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('ax-login', fn (Request $request) => [
            Limit::perMinute((int) config('anonymous_exam.admin.login_max_attempts', 5))->by('u:' . mb_strtolower((string) $request->input('username'))),
            Limit::perMinute(20)->by('ip:' . $request->ip()),
        ]);

        // Participant limits are keyed by the token / session, never by IP (no IP-derived state for this path)
        RateLimiter::for('ax-exchange', fn (Request $request) => Limit::perMinute(10)->by('x:' . hash('sha256', (string) $request->route('token'))));
        RateLimiter::for('ax-participant', fn (Request $request) => Limit::perMinute(60)->by('s:' . hash('sha256', $request->session()->getId())));
        RateLimiter::for('ax-submit', fn (Request $request) => Limit::perMinute(6)->by('s:' . hash('sha256', $request->session()->getId())));

        RateLimiter::for('ax-webhook', fn () => Limit::perMinute(600)->by('ax-webhook'));
    }
}
