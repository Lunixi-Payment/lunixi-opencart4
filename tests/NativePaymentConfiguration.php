<?php

declare (strict_types=1);
/** Explicit synthetic merchant settings shared by parent and fresh-process fixtures.
 * These are registry-only test settings; tests must not depend on a previous admin save.
 */
function nativePaymentConfiguration(\Opencart\System\Engine\Registry $registry): void
{
    $config = $registry->get('config');
    $store = new \Lunixi\OpenCart4\NativeLedgerStore($registry->get('db'), DB_PREFIX);
    foreach (['paid' => 2, 'pending' => 1, 'authorized' => 1, 'review' => 1, 'failed' => 10, 'refunded' => 11] as $state => $id) {
        $config->set('payment_lunixi_' . $state . '_status_id', in_array($state, ['pending', 'authorized', 'review'], true) ? (int) $store->meta('status:' . $state) : $id);
    }
    $config->set('payment_lunixi_mode', 'embedded');
    $config->set('payment_lunixi_status', 1);
    $config->set('payment_lunixi_preauth', 0);
    $config->set('config_customer_activity', 0);
    $config->set('fraud_ip_status', 0);
}
