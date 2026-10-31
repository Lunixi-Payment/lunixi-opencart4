<?php

declare (strict_types=1);
$file = getenv('LUNIXI_TEST_DB');
if (!is_string($file) || $file === '') {
    throw new RuntimeException('External disposable DB file path required.');
}
file_put_contents($file, json_encode(['host' => '127.0.0.1', 'port' => 3306, 'database' => 'lunixi_oc4', 'username' => 'lunixi', 'password' => getenv('LUNIXI_TEST_DATABASE_PASSWORD')], JSON_THROW_ON_ERROR));
chmod($file, 0600);
