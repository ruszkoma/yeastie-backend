<?php

/*
 * Front controller for https://yeastie.cprojekt.pl/api/ on the production host.
 * The application (app/, vendor/, writable/, .env, secrets/) lives outside the public directory;
 * tools/build.sh writes its absolute path below.
 */

use CodeIgniter\Boot;
use Config\Paths;

if (version_compare(PHP_VERSION, '8.2', '<')) {
    header('HTTP/1.1 503 Service Unavailable', true, 503);
    exit(1);
}

$appRoot = '__YEASTIE_APP_ROOT__';

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

require $appRoot . '/app/Config/Paths.php';

$paths = new Paths();

require $paths->systemDirectory . '/Boot.php';

exit(Boot::bootWeb($paths));
