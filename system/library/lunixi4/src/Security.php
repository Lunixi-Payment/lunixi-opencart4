<?php

declare (strict_types=1);
namespace Lunixi\OpenCart4;

final class Security
{
    public static function admin(\Opencart\System\Engine\Registry $registry, bool $finance = false, bool $post = true, bool $nonce = true): void
    {
        $user = $registry->get('user');
        $request = $registry->get('request');
        $session = $registry->get('session');
        $expected = $session->data['user_token'] ?? '';
        $actual = $request->get['user_token'] ?? $request->post['user_token'] ?? '';
        if (!is_object($user) || !$user->isLogged() || !$user->hasPermission('modify', 'extension/lunixi/payment/lunixi') || $finance && !$user->hasPermission('modify', 'sale/order') || !is_string($expected) || $expected === '' || !is_string($actual) || !hash_equals($expected, $actual) || $post && ($request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new \RuntimeException('Administrator permission and CSRF validation are required.');
        }
        if ($nonce) {
            $expected = $session->data['lunixi_admin_csrf'] ?? '';
            $actual = $request->post['lunixi_csrf'] ?? '';
            if (!is_string($expected) || $expected === '' || !is_string($actual) || !hash_equals($expected, $actual)) {
                throw new \RuntimeException('Administrator request nonce is invalid.');
            }
        }
    }
    public static function customer(\Opencart\System\Engine\Registry $registry, int $id, bool $post = false): void
    {
        $session = $registry->get('session');
        $request = $registry->get('request');
        if ($id < 1 || (int) ($session->data['order_id'] ?? 0) !== $id || ($session->data['payment_method']['code'] ?? '') !== 'lunixi.lunixi') {
            throw new \RuntimeException('Order session ownership is required.');
        }
        if ($post) {
            $nonce = $session->data['lunixi_checkout_csrf'] ?? '';
            $actual = $request->post['lunixi_csrf'] ?? '';
            if (($request->server['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($nonce) || $nonce === '' || !is_string($actual) || !hash_equals($nonce, $actual)) {
                throw new \RuntimeException('Checkout request nonce is invalid.');
            }
        }
        $kernel = new Kernel($registry);
        $order = $kernel->orders()->order($id);
        $customer = $registry->get('customer');
        $customerId = is_object($customer) ? (int) $customer->getId() : 0;
        if ((int) $order['customer_id'] !== $customerId) {
            throw new \RuntimeException('The authenticated customer does not own this order.');
        }
    }
}
