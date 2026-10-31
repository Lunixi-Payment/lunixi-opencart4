<?php

declare (strict_types=1);
/** Destructive installation ONLY into a separately provisioned disposable acceptance schema. */
if (getenv('LUNIXI_NATIVE_TEST_DESTRUCTIVE') !== '1') {
    throw new RuntimeException('Explicit disposable native-test installation opt-in is required.');
}
$connection = getenv('LUNIXI_TEST_DB');
$source = getenv('OPENCART_SOURCE');
$site = getenv('LUNIXI_TEST_SITE');
if (!is_string($connection) || !is_string($source) || !is_string($site) || !is_file($source . '/upload/install/cli_install.php')) {
    throw new RuntimeException('Supply official source, disposable site and task-local DB configuration paths.');
}
$db = json_decode(file_get_contents($connection), true, 16, JSON_THROW_ON_ERROR);
if (($db['database'] ?? '') !== 'lunixi_oc4') {
    throw new RuntimeException('Only the dedicated lunixi_oc4 acceptance schema is permitted.');
}
if (is_dir($site)) {
    throw new RuntimeException('Use a fresh native fixture directory.');
}
function copyNative(string $from, string $to): void
{
    if (is_dir($from)) {
        mkdir($to, 0755, true);
        foreach (new DirectoryIterator($from) as $f) {
            if (!$f->isDot()) {
                copyNative($f->getPathname(), $to . '/' . $f->getFilename());
            }
        }
    } else {
        copy($from, $to);
    }
}
copyNative($source . '/upload', $site);
copy($site . '/config-dist.php', $site . '/config.php');
copy($site . '/admin/config-dist.php', $site . '/admin/config.php');
$_SERVER['argv'] = [$site . '/install/cli_install.php', 'install', '--username', 'lunixi_admin', '--password', bin2hex(random_bytes(10)), '--email', 'synthetic@example.invalid', '--http_server', 'https://merchant.example/', '--db_driver', 'mysqli', '--db_hostname', $db['host'], '--db_username', $db['username'], '--db_password', $db['password'], '--db_database', $db['database'], '--db_port', (string) $db['port'], '--db_prefix', 'oc_', '--language', 'en-gb'];
chdir($site . '/install');
require $site . '/install/cli_install.php';
