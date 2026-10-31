<?php

declare (strict_types=1);
namespace Lunixi\OpenCart4;

use Lunixi\OpenCart4\Vendor\Lunixi\Sdk\Commerce\{Money, OrderSnapshot, PaymentDecision};
/** Native order/stock/totals/history application on the ledger's SAME DB connection. */
final class NativeOrderAdapter
{
    private \Opencart\System\Engine\Registry $registry;
    private NativeLedgerStore $store;
    private int $storeId;
    public function __construct(\Opencart\System\Engine\Registry $registry, NativeLedgerStore $store, int $storeId)
    {
        $this->registry = $registry;
        $this->store = $store;
        $this->storeId = $storeId;
    }
    public function order(int $id, bool $lock = false): array
    {
        $q = $this->store->query('SELECT * FROM `' . $this->store->prefix() . 'order` WHERE order_id=' . $id . ' AND store_id=' . $this->storeId . ($lock ? ' FOR UPDATE' : ''));
        if ($q->num_rows !== 1 || (json_decode((string) ($q->row['payment_method'] ?? ''), true)['code'] ?? '') !== 'lunixi.lunixi') {
            throw new \RuntimeException('The order does not belong to this store/payment method.');
        }
        return $q->row;
    }
    public function orderKey(int $id): string
    {
        return 'oc4:' . $this->storeId . ':' . $id;
    }
    public function snapshot(array $order, string $merchantId): OrderSnapshot
    {
        $currency = (string) $order['currency_code'];
        $row = $this->store->query('SELECT decimal_place FROM `' . $this->store->prefix() . 'currency` WHERE code=' . $this->store->quote($currency))->row;
        if (!isset($row['decimal_place']) || !is_string($order['total']) || !is_string($order['currency_value'])) {
            throw new \RuntimeException('Persisted exact decimal currency values are required.');
        }
        $exponent = Money::exponent($currency);
        if ((int) $row['decimal_place'] !== $exponent) {
            throw new \RuntimeException('Platform currency precision does not match ISO 4217.');
        }
        $minor = Money::convertedMinor($order['total'], $order['currency_value'], $exponent);
        return new OrderSnapshot($merchantId, $this->orderKey((int) $order['order_id']), $minor, $currency);
    }
    public function activeStatuses(): array
    {
        $config = $this->registry->get('config');
        return array_map('intval', (array) $config->get('config_processing_status') + (array) $config->get('config_complete_status'));
    }
    public function apply(string $orderKey, PaymentDecision $decision): void
    {
        $id = $this->store->nativeId($orderKey);
        $order = $this->order($id, true);
        $record = $this->store->load($orderKey);
        if ($record === null || $this->snapshot($order, (string) $record['snapshot']['merchantId'])->fingerprint() !== OrderSnapshot::fromArray($record['snapshot'])->fingerprint()) {
            throw new \RuntimeException('Native order changed after checkout reservation.');
        }
        $hash = hash('sha256', json_encode([$decision->state, $decision->paymentId, $decision->chargedAmount, $decision->capturedAmount, $decision->refundedAmount, $decision->gatewayStatus], JSON_THROW_ON_ERROR));
        $application = $this->store->query('SELECT * FROM `' . $this->store->prefix() . 'lunixi_application` WHERE order_key=' . $this->store->quote($orderKey) . ' FOR UPDATE')->row;
        if (($application['decision_hash'] ?? '') === $hash) {
            return;
        }
        $config = $this->registry->get('config');
        $mapping = ['paid' => 'paid', 'partially_refunded' => 'paid', 'authorized' => 'authorized', 'partially_captured' => 'review', 'refunded' => 'refunded', 'failed' => 'failed', 'pending' => 'pending', 'review' => 'review'];
        $state = $mapping[$decision->state] ?? 'review';
        $target = (int) $config->get('payment_lunixi_' . $state . '_status_id');
        if ($target < 1 || $state === 'paid' && !in_array($target, $this->activeStatuses(), true) || $state !== 'paid' && in_array($target, $this->activeStatuses(), true)) {
            throw new \RuntimeException('Payment status mappings are unsafe.');
        }
        $before = (int) $order['order_status_id'];
        // Preserve native fraud/manual holds; observing an additional refund must not release fulfillment.
        $manualChange = $application !== [] && (int) $application['native_status_id'] !== $before && !in_array($before, $this->activeStatuses(), true);
        $existingHold = $application !== [] && in_array($application['state'], ['paid', 'partially_refunded'], true) && !in_array($before, $this->activeStatuses(), true);
        $firstManualHold = $application === [] && $before !== 0 && $before !== (int) $config->get('payment_lunixi_pending_status_id');
        if ($state === 'paid' && ($manualChange || $existingHold || $firstManualHold)) {
            $target = $before;
        }
        $this->registry->get('load')->language('extension/lunixi/payment/lunixi');
        $comment = $this->registry->get('language')->get('history_' . $decision->state);
        if (!is_string($comment) || $comment === 'history_' . $decision->state) {
            $comment = $this->registry->get('language')->get('history_review');
        }
        $events = $this->registry->get('event');
        $this->registry->set('event', new DeferredHistoryEvents($this->registry, $events));
        try {
            $this->registry->get('load')->model('checkout/order');
            // Native fraud remains active; it can replace target with its own fulfillment hold.
            $this->registry->get('model_checkout_order')->addHistory($id, $target, $comment, false, false);
        } finally {
            $this->registry->set('event', $events);
        }
        $actual = (int) $this->order($id)['order_status_id'];
        $this->store->query('INSERT INTO `' . $this->store->prefix() . 'lunixi_application` (order_key,decision_hash,payment_id,captured_minor,refunded_minor,charged_minor,state,native_status_id) VALUES (' . $this->store->quote($orderKey) . ',' . $this->store->quote($hash) . ',' . $this->store->quote($decision->paymentId) . ',' . $decision->capturedAmount . ',' . $decision->refundedAmount . ',' . $decision->chargedAmount . ',' . $this->store->quote($decision->state) . ',' . $actual . ') ON DUPLICATE KEY UPDATE decision_hash=VALUES(decision_hash),payment_id=VALUES(payment_id),captured_minor=VALUES(captured_minor),refunded_minor=VALUES(refunded_minor),charged_minor=VALUES(charged_minor),state=VALUES(state),native_status_id=VALUES(native_status_id)');
        $this->store->query('INSERT INTO `' . $this->store->prefix() . 'lunixi_outbox` (order_key,native_order_id,old_status,new_status,comment_code,created_at) VALUES (' . $this->store->quote($orderKey) . ',' . $id . ',' . $before . ',' . $actual . ',' . $this->store->quote('history_' . $decision->state) . ',' . time() . ')');
    }
    /** Claim durably BEFORE external mail. Interrupted sending requires operator review, never blind resend. */
    public function deliverNotifications(int $limit = 20): array
    {
        if ($this->store->isActive()) {
            throw new \RuntimeException('Notifications require a committed native transaction.');
        }
        $result = ['sent' => 0, 'uncertain' => 0];
        $rows = $this->store->query('SELECT o.* FROM `' . $this->store->prefix() . 'lunixi_outbox` o JOIN `' . $this->store->prefix() . 'lunixi_attempt` a ON a.order_key=o.order_key WHERE a.store_id=' . $this->storeId . " AND o.state='pending' ORDER BY o.outbox_id LIMIT " . max(1, min(100, $limit)))->rows;
        foreach ($rows as $row) {
            $this->store->query('UPDATE `' . $this->store->prefix() . "lunixi_outbox` SET state='sending' WHERE outbox_id=" . (int) $row['outbox_id'] . " AND state='pending'");
            if ($this->registry->get('db')->countAffected() !== 1) {
                continue;
            }
            try {
                $this->registry->get('load')->model('checkout/order');
                $info = $this->registry->get('model_checkout_order')->getOrder((int) $row['native_order_id']);
                $this->registry->get('load')->language('extension/lunixi/payment/lunixi');
                $comment = $this->registry->get('language')->get($row['comment_code']);
                require_once DIR_APPLICATION . 'controller/mail/order.php';
                $mailer = new \Opencart\Catalog\Controller\Mail\Order($this->registry);
                if ((int) $row['old_status'] === 0) {
                    $mailer->add($info, (int) $row['new_status'], $comment, true);
                } else {
                    $mailer->history($info, (int) $row['new_status'], $comment, true);
                }
                // Alert hook expects the pre-transition status. Expose that snapshot only during mail, never by mutating the order.
                $nativeModel = $this->registry->get('model_checkout_order');
                $this->registry->set('model_checkout_order', new class($nativeModel, (int) $row['native_order_id'], (int) $row['old_status'])
                {
                    private object $native;
                    private int $id;
                    private int $status;
                    public function __construct(object $native, int $id, int $status)
                    {
                        $this->native = $native;
                        $this->id = $id;
                        $this->status = $status;
                    }
                    public function __call(string $method, array $args)
                    {
                        $value = $this->native->{$method}(...$args);
                        if ($method === 'getOrder' && (int) ($args[0] ?? 0) === $this->id && is_array($value)) {
                            $value['order_status_id'] = $this->status;
                        }
                        return $value;
                    }
                });
                try {
                    $route = 'checkout/order/addHistory';
                    $args = [(int) $row['native_order_id'], (int) $row['new_status'], $comment, true];
                    $mailer->alert($route, $args);
                } finally {
                    $this->registry->set('model_checkout_order', $nativeModel);
                }
                $this->store->query('UPDATE `' . $this->store->prefix() . "lunixi_outbox` SET state='sent' WHERE outbox_id=" . (int) $row['outbox_id']);
                $result['sent']++;
            } catch (\Throwable $error) {
                $this->store->query('UPDATE `' . $this->store->prefix() . "lunixi_outbox` SET state='uncertain' WHERE outbox_id=" . (int) $row['outbox_id']);
                $result['uncertain']++;
            }
        }
        return $result;
    }
}
