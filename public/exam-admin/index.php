<?php

/*
 * The panel URL (/exam-admin) and its asset folder (public/exam-admin/) share a name, so web servers
 * (php -S, Apache) map "/exam-admin" to this directory instead of the Laravel front controller.
 * This shim hands the request to public/index.php and restores the script name so that Laravel
 * computes an empty base URL (routes and generated links stay /exam-admin/...).
 */
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php';
