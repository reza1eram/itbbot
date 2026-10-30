<?php

namespace App\Modules\AnonymousExam\Console\Commands;

use App\Modules\AnonymousExam\Models\AdminUser;
use Illuminate\Console\Command;

class MakeAdminCommand extends Command
{
    protected $signature = 'exam:make-admin
        {--username= : login name}
        {--name= : display name}
        {--role=owner : owner|editor|viewer}
        {--password= : password (omit to be asked interactively; avoid on shared machines)}';

    protected $description = 'Create an admin user for the anonymous exam panel';

    public function handle(): int
    {
        $username = $this->option('username') ?: $this->ask('Username', 'admin');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', (string) $username)) {
            $this->error('Username must be 3-60 chars: letters, digits, _ . -');

            return self::FAILURE;
        }
        if (AdminUser::query()->where('username', $username)->exists()) {
            $this->error("Admin '{$username}' already exists.");

            return self::FAILURE;
        }

        $role = (string) $this->option('role');
        if (!in_array($role, AdminUser::ROLES, true)) {
            $this->error('Role must be owner, editor or viewer.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Display name', $username);
        $password = $this->option('password');
        if (!$password) {
            $password = (string) $this->secret('Password (min 10 chars)');
            if ($password !== (string) $this->secret('Repeat password')) {
                $this->error('Passwords do not match.');

                return self::FAILURE;
            }
        }
        if (strlen((string) $password) < 10) {
            $this->error('Password must be at least 10 characters.');

            return self::FAILURE;
        }

        AdminUser::query()->create([
            'name' => $name,
            'username' => $username,
            'password' => $password,
            'role' => $role,
            'is_active' => true,
        ]);
        $this->info("Admin '{$username}' ({$role}) created.");

        return self::SUCCESS;
    }
}
