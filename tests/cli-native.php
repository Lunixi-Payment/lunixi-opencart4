<?php

declare (strict_types=1);
if (getenv('LUNIXI_NATIVE_TEST_DESTRUCTIVE') !== '1') {
    throw new RuntimeException('Explicit disposable fixture opt-in required.');
}
putenv('LUNIXI_TEST_ADMIN=1');
require __DIR__ . '/native_bootstrap.php';
putenv('LUNIXI_OPENCART_ENCRYPTION_KEY=' . base64_encode(str_repeat('t', 32)));
$r = nativeRegistry();
$r->get('config')->set('application', 'Admin');
$r->get('load')->model('setting/store');
$r->get('load')->model('setting/setting');
$r->get('load')->model('setting/extension');
if (!$r->get('db')->query("SELECT * FROM `" . DB_PREFIX . "extension` WHERE type='payment' AND code='lunixi'")->num_rows) {
    $r->get('model_setting_extension')->install('payment', 'lunixi', 'lunixi');
}
$id = (int) $r->get('model_setting_store')->addStore(['config_name' => 'Synthetic CLI acceptance', 'config_url' => 'https://lunixi-worker-check.example/', 'config_ssl' => 'https://lunixi-worker-check.example/']);
try {
    $r->get('model_setting_setting')->editSetting('payment_lunixi', ['payment_lunixi_status' => '0', 'payment_lunixi_profile_id' => '0'], $id);
    // Per-store selection must not inherit another store's merchant configuration.
    \Lunixi\OpenCart4\StoreContext::select($r, $id);
    if ((int) $r->get('config')->get('payment_lunixi_profile_id') !== 0) {
        throw new RuntimeException('Cross-store credentials inherited.');
    }
    $site = getenv('LUNIXI_TEST_SITE');
    $pipes = [];
    $process = proc_open([PHP_BINARY, $site . '/extension/lunixi/system/library/lunixi4/cli.php', '--root=' . $site, '--store-id=' . $id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $site);
    if (!is_resource($process)) {
        throw new RuntimeException('CLI test process unavailable.');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $result = json_decode($out, true);
    if ($code !== 0 || !is_array($result) || isset($result['error']) || ($result['failed'] ?? -1) !== 0 || ($result['sent'] ?? -1) !== 0) {
        throw new RuntimeException('Native CLI entrypoint failed: ' . $out . $err);
    }
    echo "Actual native catalog CLI entrypoint and store isolation checks passed.\n";
} finally {
    $r->get('model_setting_store')->deleteStore($id);
    $r->get('model_setting_setting')->deleteSetting('payment_lunixi', $id);
}
