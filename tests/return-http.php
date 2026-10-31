<?php

declare (strict_types=1);
// Actual native index.php + startup/middleware/controller over loopback HTTP.
require __DIR__ . '/native_bootstrap.php';
$r = nativeRegistry();
$db = $r->get('db');
function financialSnapshot($db): string
{
    $tables = ['order', 'order_history', 'order_product', 'product'];
    foreach ($db->query("SHOW TABLES LIKE '" . DB_PREFIX . "lunixi_%'")->rows as $row) {
        $table = (string) array_values($row)[0];
        $tables[] = substr($table, strlen(DB_PREFIX));
    }
    sort($tables);
    $state = [];
    foreach (array_unique($tables) as $table) {
        $rows = $db->query('SELECT * FROM `' . DB_PREFIX . $table . '`')->rows;
        $rows = array_map(static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($rows);
        $state[$table] = $rows;
    }
    return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
}
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!is_resource($listener)) {
    throw new RuntimeException('Loopback listener unavailable.');
}
$address = stream_socket_get_name($listener, false);
fclose($listener);
$log = tempnam(sys_get_temp_dir(), 'lunixi-native-http-');
$pipes = [];
$process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/http-router.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Native HTTP server unavailable.');
}
fclose($pipes[0]);
try {
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(20000);
    }
    if (!$ready) {
        throw new RuntimeException('Native HTTP startup failed.');
    }
    $before = financialSnapshot($db);
    $target = '/index.php?route=extension/lunixi/payment/lunixi.returned&order_id=123';
    $send = static function (string $body) use ($address, $target): array {
        $curl = curl_init('http://' . $address . $target);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Host: merchant.example', 'Content-Type: application/x-www-form-urlencoded'], CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($response)) {
            throw new RuntimeException('Native HTTP response unavailable.');
        }
        return [$status, $response];
    };
    // No Cookie/CSRF token; posted IDs/status/redirect are all malicious and ignored.
    [$status, $response] = $send('order_id=999&status=success&paymentId=pi_forged&redirect=https://evil.invalid');
    if ($status !== 303 || !str_contains($response, 'Location: ?route=extension/lunixi/payment/lunixi.returned&order_id=123') || !str_contains(strtolower($response), 'cache-control: no-store') || str_contains($response, 'pi_forged') || str_contains($response, 'evil.invalid')) {
        throw new RuntimeException('Native no-session POST did not produce the fixed-origin303 hop: ' . $response);
    }
    [$status] = $send(str_repeat('x', 65537));
    if ($status !== 413) {
        throw new RuntimeException('Oversized callback POST accepted.');
    }
    // The GET hop does not acquire ownership from a posted/query success flag.
    $curl = curl_init('http://' . $address . $target . '&status=success&paymentId=pi_forged');
    curl_setopt_array($curl, [CURLOPT_HTTPHEADER => ['Host: merchant.example'], CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10]);
    $anonymousGet = curl_exec($curl);
    $getStatus = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($anonymousGet) || $getStatus !== 200 || preg_match('/^Location:.*checkout[\/\.]success/im', $anonymousGet)) {
        throw new RuntimeException('Anonymous return GET bypassed the native owner gate.');
    }
    if ($before !== financialSnapshot($db)) {
        throw new RuntimeException('Browser POST changed financial/native order state.');
    }
    echo "Actual native HTTP no-session POST303, ignored browser fields, body bound, anonymous GET owner gate and unchanged financial tables passed.\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    @unlink($log);
}
