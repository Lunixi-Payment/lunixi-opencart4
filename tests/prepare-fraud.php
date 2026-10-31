<?php

declare (strict_types=1);
if (getenv('LUNIXI_NATIVE_TEST_DESTRUCTIVE') !== '1') {
    throw new RuntimeException('Explicit disposable fixture opt-in required.');
}
require __DIR__ . '/native_bootstrap.php';
$registry = nativeRegistry();
(new Opencart\Admin\Model\Extension\Opencart\Fraud\Ip($registry))->install();
$registry->get('db')->query("INSERT INTO `" . DB_PREFIX . "extension` (extension,type,code) SELECT 'opencart','fraud','ip' WHERE NOT EXISTS (SELECT 1 FROM `" . DB_PREFIX . "extension` WHERE type='fraud' AND code='ip')");
$statusModel = new Opencart\Admin\Model\Localisation\OrderStatus($registry);
$store = new Lunixi\OpenCart4\NativeLedgerStore($registry->get('db'), DB_PREFIX);
$store->install();
foreach (['pending', 'authorized', 'review'] as $state) {
    $id = (int) $store->meta('status:' . $state);
    if ($id < 1 || !$statusModel->getOrderStatus($id)) {
        $id = (int) $statusModel->addOrderStatus(['order_status' => [1 => ['name' => $registry->get('language')->get('status_' . $state)]]]);
        $store->putMeta('status:' . $state, (string) $id);
    }
}
echo "Official native fraud module installed into acceptance schema.\n";
