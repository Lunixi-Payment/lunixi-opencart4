<?php

declare (strict_types=1);
if (getenv('LUNIXI_NATIVE_TEST_DESTRUCTIVE') !== '1') {
    throw new RuntimeException('Explicit disposable native schema mutation opt-in is required.');
}
putenv('LUNIXI_TEST_ADMIN=1');
require __DIR__ . '/native_bootstrap.php';
putenv('LUNIXI_OPENCART_ENCRYPTION_KEY=' . base64_encode(str_repeat('t', 32)));
$r = nativeRegistry();
$db = $r->get('db');
$route = 'extension/lunixi/payment/lunixi';
function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
$old = $db->query('SELECT permission FROM `' . DB_PREFIX . 'user_group` WHERE user_group_id=1')->row['permission'];
try {
    $permissions = json_encode(['access' => [$route], 'modify' => [$route, 'sale/order']], JSON_THROW_ON_ERROR);
    $db->query("UPDATE `" . DB_PREFIX . "user_group` SET permission='" . $db->escape($permissions) . "' WHERE user_group_id=1");
    $r->get('session')->data = ['user_id' => 1, 'user_token' => 'native-admin-token', 'lunixi_admin_csrf' => 'synthetic-admin-nonce'];
    $r->set('user', new Opencart\System\Library\Cart\User($r));
    $request = $r->get('request');
    $request->get = ['user_token' => 'native-admin-token'];
    $request->server['REQUEST_METHOD'] = 'POST';
    $request->post = ['lunixi_csrf' => 'invalid'];
    try {
        \Lunixi\OpenCart4\Security::admin($r, true);
        throw new LogicException('Bad nonce accepted.');
    } catch (RuntimeException $expected) {
    }
    $request->post = ['lunixi_csrf' => 'synthetic-admin-nonce'];
    \Lunixi\OpenCart4\Security::admin($r, true);
    $request->get['user_token'] = 'invalid';
    try {
        \Lunixi\OpenCart4\Security::admin($r, true);
        throw new LogicException('Bad native CSRF accepted.');
    } catch (RuntimeException $expected) {
    }
    $request->get['user_token'] = 'native-admin-token';
    $controller = new Opencart\Admin\Controller\Extension\Lunixi\Payment\Lunixi($r);
    $request->server['REQUEST_METHOD'] = 'GET';
    $controller->install();
    $controller->index();
    $html = $r->get('response')->getOutput();
    check(str_contains($html, 'payment_lunixi_private_key'), 'Native admin key form missing.');
    check(!str_contains($html, 'BEGIN PRIVATE KEY'), 'Private signing key reflected into form.');
    check(!str_contains($html, 'synthetic_webhook_secret_'), 'Webhook secret reflected into form.');
    check(str_contains($html, 'Lunixi'), 'Native admin template did not render.');
    $before = $db->query('SELECT COUNT(*) AS total FROM `' . DB_PREFIX . 'lunixi_attempt`')->row['total'];
    $controller->uninstall();
    check($before === $db->query('SELECT COUNT(*) AS total FROM `' . DB_PREFIX . 'lunixi_attempt`')->row['total'], 'Uninstall deleted payment history.');
    $permissions = json_encode(['access' => [$route], 'modify' => [$route]], JSON_THROW_ON_ERROR);
    $db->query("UPDATE `" . DB_PREFIX . "user_group` SET permission='" . $db->escape($permissions) . "' WHERE user_group_id=1");
    $r->set('user', new Opencart\System\Library\Cart\User($r));
    $request->server['REQUEST_METHOD'] = 'POST';
    try {
        \Lunixi\OpenCart4\Security::admin($r, true);
        throw new LogicException('Finance permission bypass.');
    } catch (RuntimeException $expected) {
    }
    echo "Native admin install/form/CSRF/finance permission/history retention checks passed.\n";
} finally {
    $db->query("UPDATE `" . DB_PREFIX . "user_group` SET permission='" . $db->escape($old) . "' WHERE user_group_id=1");
}
