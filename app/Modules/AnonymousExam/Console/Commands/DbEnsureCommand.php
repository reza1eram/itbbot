<?php

namespace App\Modules\AnonymousExam\Console\Commands;

use Illuminate\Console\Command;
use PDO;
use Throwable;

/** Used by start.bat: creates the configured MySQL database (utf8mb4) when it does not exist yet */
class DbEnsureCommand extends Command
{
    protected $signature = 'exam:db-ensure';

    protected $description = 'Create the MySQL database from .env if missing (utf8mb4_unicode_ci)';

    public function handle(): int
    {
        $c = config('database.connections.mysql');
        $name = (string) $c['database'];
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            $this->error('DB_DATABASE contains unsupported characters.');

            return self::FAILURE;
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $c['host'], $c['port']),
                (string) $c['username'],
                (string) $c['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            // identifier validated above; identifiers cannot be bound as parameters
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $e) {
            $this->error('MySQL error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("Database {$name} is ready.");

        return self::SUCCESS;
    }
}
