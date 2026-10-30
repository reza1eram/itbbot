<?php

namespace App\Modules\AnonymousExam\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prepares .env for the anonymous exam module (used by start.bat).
 * - creates .env from .env.example when missing
 * - generates ANON_HMAC_SECRET and BALE_WEBHOOK_SECRET ONCE (never overwritten)
 * - asks for BALE_BOT_TOKEN locally when it is empty (input hidden, only last 4 chars shown)
 */
class EnvSetupCommand extends Command
{
    protected $signature = 'exam:env-setup {--no-prompt : never ask for the bot token}';

    protected $description = 'Create/complete .env for the anonymous exam module without overwriting secrets';

    public function handle(): int
    {
        $path = base_path('.env');
        $created = false;
        if (!file_exists($path)) {
            if (!copy(base_path('.env.example'), $path)) {
                $this->error('Could not create .env');

                return self::FAILURE;
            }
            $created = true;
            $this->info('.env created from .env.example');
        }

        $env = (string) file_get_contents($path);

        if ($created) {
            // Values that only make sense for a fresh local install
            $env = $this->set($env, 'APP_URL', 'http://127.0.0.1:8000');
            $env = $this->set($env, 'DB_DATABASE', 'bale_exam_bot');
            $env = $this->set($env, 'DB_USERNAME', 'root');
            $env = $this->set($env, 'DB_PASSWORD', '');
        }

        $defaults = [
            'BALE_BASE_URL' => 'https://tapi.bale.ai',
            'BALE_BOT_USERNAME' => 'ITBbot',
            'BALE_UPDATE_MODE' => 'polling',
            'BALE_MEMBERSHIP_MODE' => 'getchatmember',
            'BALE_JOIN_BUTTON' => 'both',
            'APP_TIMEZONE' => 'Asia/Tehran',
            'ANON_TOKEN_TTL_MINUTES' => '20',
            'ANON_MIN_RESPONSES' => '5',
            'ANON_POOL_MODE' => 'false',
        ];
        foreach ($defaults as $key => $value) {
            if ($this->get($env, $key) === null) {
                $env = $this->set($env, $key, $value);
            }
        }
        foreach (['EXAM_PUBLIC_URL', 'BALE_BOT_TOKEN'] as $key) {
            if ($this->get($env, $key) === null) {
                $env = $this->set($env, $key, '');
            }
        }

        // Created once, never rotated: rotating breaks duplicate-vote protection of running exams
        if (($this->get($env, 'ANON_HMAC_SECRET') ?? '') === '') {
            $env = $this->set($env, 'ANON_HMAC_SECRET', bin2hex(random_bytes(64)));
            $this->info('ANON_HMAC_SECRET generated (keep it forever; back it up with .env).');
        }
        if (($this->get($env, 'BALE_WEBHOOK_SECRET') ?? '') === '') {
            $env = $this->set($env, 'BALE_WEBHOOK_SECRET', bin2hex(random_bytes(24)));
        }

        if (($this->get($env, 'BALE_BOT_TOKEN') ?? '') === '' && !$this->option('no-prompt') && $this->input->isInteractive()) {
            $this->warn('BALE_BOT_TOKEN is empty. Get it from @botfather in Bale. It is stored only in .env.');
            $token = trim((string) $this->secret('Paste the bot token (input hidden, empty to skip)'));
            if ($token !== '') {
                if (!preg_match('/^\d+:[A-Za-z0-9_-]{10,}$/', $token)) {
                    $this->warn('That does not look like a Bale token (digits:secret). Saved anyway.');
                }
                $env = $this->set($env, 'BALE_BOT_TOKEN', $token);
                $this->info('Token saved (…' . substr($token, -4) . ').');
            }
        }

        file_put_contents($path, $env);
        $this->info('.env is ready.');

        return self::SUCCESS;
    }

    private function get(string $env, string $key): ?string
    {
        if (!preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $env, $m)) {
            return null;
        }

        return trim(trim($m[1]), '"\'');
    }

    private function set(string $env, string $key, string $value): string
    {
        $line = $key . '=' . (preg_match('/[\s#"]/', $value) ? '"' . addcslashes($value, '"\\') . '"' : $value);
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
        if (preg_match($pattern, $env)) {
            return (string) preg_replace_callback($pattern, fn () => $line, $env, 1);
        }

        return rtrim($env, "\r\n") . "\n" . $line . "\n";
    }
}
