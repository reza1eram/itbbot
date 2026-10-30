<?php

/*
 * Router for `php artisan serve` (Laravel uses this file instead of its built-in one when present).
 * Identical to Laravel's default except:
 *  - only real FILES are served directly; directories (e.g. public/exam-admin, which holds panel assets
 *    and shares its name with the /exam-admin route) go to Laravel
 *  - SCRIPT_NAME is normalized, because PHP's built-in server would otherwise pick a nested index.php
 *    and Laravel would compute a wrong base URL
 */

$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $publicPath.DIRECTORY_SEPARATOR.'index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require_once $publicPath.'/index.php';
