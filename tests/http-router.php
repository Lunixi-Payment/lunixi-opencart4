<?php

declare (strict_types=1);
// Test-only trusted TLS termination for the real native HTTP front controller.
$site = getenv('LUNIXI_TEST_SITE');
if (!is_string($site) || !is_file($site . '/config.php') || PHP_SAPI !== 'cli-server') {
    throw new RuntimeException('An explicitly installed disposable native site is required.');
}
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REQUEST_SCHEME'] = 'https';
chdir($site);
require $site . '/index.php';
