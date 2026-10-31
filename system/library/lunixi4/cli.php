<?php

declare (strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
/** Run in the genuine native catalog context; does not reimplement native loaders/order behavior. */
$options = getopt('', ['root:', 'store-id:']);
$root = isset($options['root']) ? realpath($options['root']) : false;
if ($root === false || !is_file($root . '/config.php') || !is_file($root . '/index.php')) {
    fwrite(STDERR, "Supply --root=/absolute/opencart/upload directory.\n");
    exit(1);
}
require_once $root . '/config.php';
if (!defined('HTTP_SERVER') || !is_string(HTTP_SERVER)) {
    fwrite(STDERR, "Native store URL is unavailable.\n");
    exit(1);
}
$storeId = isset($options['store-id']) ? (int) $options['store-id'] : 0;
$native = mysqli_init();
$flags = 0;
if (defined('DB_SSL_CA') && DB_SSL_CA !== '') {
    $native->ssl_set(defined('DB_SSL_KEY') ? DB_SSL_KEY : null, defined('DB_SSL_CERT') ? DB_SSL_CERT : null, DB_SSL_CA, null, null);
    $flags = MYSQLI_CLIENT_SSL;
}
$native->real_connect(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int) DB_PORT, null, $flags);
if ($storeId > 0) {
    $q = $native->prepare('SELECT url FROM `' . DB_PREFIX . 'store` WHERE store_id=?');
    $q->bind_param('i', $storeId);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    if (!$row) {
        fwrite(STDERR, "Unknown store ID.\n");
        exit(1);
    }
    $base = $row['url'];
} else {
    $q = $native->query("SELECT value FROM `" . DB_PREFIX . "setting` WHERE store_id=0 AND `key`='config_url' LIMIT 1");
    $row = $q->fetch_assoc();
    $base = $row['value'] ?? '' ?: HTTP_SERVER;
}
$native->close();
$url = parse_url($base);
if (($url['scheme'] ?? '') !== 'https' || !isset($url['host']) || isset($url['user']) || isset($url['pass'])) {
    fwrite(STDERR, "Configure the native HTTPS store URL.\n");
    exit(1);
}
$host = $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
$prefix = rtrim($url['path'] ?? '', '/');
$_SERVER['HTTP_HOST'] = $host;
$_SERVER['SERVER_NAME'] = $host;
$_SERVER['SERVER_PORT'] = 443;
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Lunixi-Reconciliation/0.1.3';
$_SERVER['REQUEST_URI'] = $prefix . '/index.php?route=extension/lunixi/payment/lunixi.cron';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_GET = ['route' => 'extension/lunixi/payment/lunixi.cron'];
$_POST = [];
$_REQUEST = $_GET;
require $root . '/index.php';
// Make worker failures visible to cron monitoring after the native response is emitted.
if (isset($registry) && $registry->has('response')) {
    $result = json_decode($registry->get('response')->getOutput(), true);
    if (!is_array($result) || isset($result['error']) || (int) ($result['failed'] ?? 0) > 0) {
        exit(1);
    }
}
