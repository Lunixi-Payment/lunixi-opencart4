<?php

declare (strict_types=1);
namespace Lunixi\OpenCart4;

use Lunixi\OpenCart4\Vendor\Lunixi\Sdk\LunixiClient;
use Lunixi\OpenCart4\Vendor\Lunixi\Sdk\Commerce\{CheckoutService, SecretBox, PaymentDecision, CheckoutSession};
/** Thin native wiring; SDK Commerce owns all payment identity, settlement and mutation decisions. */
final class Kernel
{
    private \Opencart\System\Engine\Registry $registry;
    private int $storeId;
    private NativeLedgerStore $store;
    private NativeOrderAdapter $orders;
    private ?LunixiClient $testClient;
    public function __construct(\Opencart\System\Engine\Registry $registry, ?LunixiClient $testClient = null)
    {
        $this->registry = $registry;
        $this->storeId = (int) $registry->get('config')->get('config_store_id');
        $profile = (int) $registry->get('config')->get('payment_lunixi_profile_id');
        $this->store = new NativeLedgerStore($registry->get('db'), DB_PREFIX, $profile);
        $this->orders = new NativeOrderAdapter($registry, $this->store, $this->storeId);
        $this->testClient = $testClient;
    }
    public function store(): NativeLedgerStore
    {
        return $this->store;
    }
    public function orders(): NativeOrderAdapter
    {
        return $this->orders;
    }
    public function compatibility(): Compatibility
    {
        return new Compatibility($this->registry, $this->store);
    }
    public static function secretBox(): SecretBox
    {
        $encoded = getenv('LUNIXI_OPENCART_ENCRYPTION_KEY');
        if (!is_string($encoded)) {
            throw new \RuntimeException('A separate server encryption key is required.');
        }
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new \RuntimeException('A base64 32-byte server encryption key is required.');
        }
        return new SecretBox($key);
    }
    public static function origin(): string
    {
        $origin = getenv('LUNIXI_GATEWAY_ORIGIN') ?: 'https://api-gateway.lunixi.com';
        $allowed = ['https://api-gateway.lunixi.com'];
        $extra = getenv('LUNIXI_ALLOWED_GATEWAY_ORIGINS');
        if (is_string($extra) && $extra !== '') {
            $allowed = array_merge($allowed, json_decode($extra, true, 16, JSON_THROW_ON_ERROR));
        }
        $u = parse_url($origin);
        if (!in_array($origin, $allowed, true) || ($u['scheme'] ?? '') !== 'https' || !isset($u['host']) || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment']) || isset($u['path']) && $u['path'] !== '') {
            throw new \RuntimeException('The operator-approved gateway origin is invalid.');
        }
        return $origin;
    }
    /** Secret inputs are never reflected into HTML; immutable encrypted profiles retain financial history. */
    public function saveProfile(array $inputs): int
    {
        $current = (int) $this->registry->get('config')->get('payment_lunixi_profile_id');
        $credentials = $current > 0 ? $this->profile($current) : [];
        foreach (['merchant_id' => 'merchantId', 'key_id' => 'keyId', 'private_key' => 'privateKey', 'webhook_secret' => 'webhookSecret'] as $field => $key) {
            if (isset($inputs['payment_lunixi_' . $field]) && is_string($inputs['payment_lunixi_' . $field]) && trim($inputs['payment_lunixi_' . $field]) !== '') {
                $credentials[$key] = $inputs['payment_lunixi_' . $field];
            }
        }
        foreach (['merchantId', 'keyId', 'privateKey', 'webhookSecret'] as $key) {
            if (!is_string($credentials[$key] ?? null) || trim($credentials[$key]) === '') {
                throw new \RuntimeException('Merchant credentials are required.');
            }
        }
        if (strlen($credentials['merchantId']) > 200 || strlen($credentials['webhookSecret']) < 16 || strlen($credentials['webhookSecret']) > 1024) {
            throw new \RuntimeException('Credential validation failed.');
        }
        // Constructing validates the signing key without calling the gateway.
        LunixiClient::create(['baseUrl' => self::origin(), 'keyId' => $credentials['keyId'], 'privateKey' => $credentials['privateKey'], 'maxRetries' => 0]);
        if ($current > 0 && $credentials === $this->profile($current)) {
            return $current;
        }
        $cipher = self::secretBox()->encrypt(json_encode($credentials, JSON_THROW_ON_ERROR));
        $this->store->query('INSERT INTO `' . DB_PREFIX . 'lunixi_profile` (store_id,merchant_id,ciphertext,created_at) VALUES (' . $this->storeId . ',' . $this->store->quote($credentials['merchantId']) . ',' . $this->store->quote($cipher) . ',' . time() . ')');
        return (int) $this->registry->get('db')->getLastId();
    }
    private function profile(int $id): array
    {
        $row = $this->store->query('SELECT ciphertext FROM `' . DB_PREFIX . 'lunixi_profile` WHERE profile_id=' . $id . ' AND store_id=' . $this->storeId)->row;
        if (!isset($row['ciphertext'])) {
            throw new \RuntimeException('Retained merchant profile is unavailable.');
        }
        $value = json_decode(self::secretBox()->decrypt($row['ciphertext']), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \RuntimeException('Retained merchant profile is invalid.');
        }
        return $value;
    }
    private function profileFor(string $key): array
    {
        $id = $this->store->profileId($key);
        if ($id < 1) {
            $id = (int) $this->registry->get('config')->get('payment_lunixi_profile_id');
        }
        return $this->profile($id);
    }
    private function service(string $key): CheckoutService
    {
        $credentials = $this->profileFor($key);
        $client = $this->testClient ?? LunixiClient::create(['baseUrl' => self::origin(), 'keyId' => $credentials['keyId'], 'privateKey' => $credentials['privateKey'], 'maxRetries' => 0, 'timeout' => 30, 'userAgent' => 'lunixi-opencart4/1.0.1']);
        return new CheckoutService($client, $this->store, self::secretBox());
    }
    public function assertConfigured(): void
    {
        $id = (int) $this->registry->get('config')->get('payment_lunixi_profile_id');
        if ($id < 1) {
            throw new \RuntimeException('A store-specific merchant profile is required.');
        }
        $this->profile($id);
        self::origin();
        self::secretBox();
    }
    /** Read-only browser completion check against the same frozen order snapshot. */
    public function browserComplete(int $id): bool
    {
        $key = $this->orders->orderKey($id);
        return $this->store->transaction($key, function () use ($key, $id): bool {
            $bound = $this->store->load($key);
            if ($bound === null || !isset($bound['snapshot'])) {
                return false;
            }
            $order = $this->orders->order($id, true);
            if ($this->orders->snapshot($order, (string) $bound['snapshot']['merchantId'])->fingerprint() !== \Lunixi\OpenCart4\Vendor\Lunixi\Sdk\Commerce\OrderSnapshot::fromArray($bound['snapshot'])->fingerprint()) {
                return false;
            }
            $row = $this->store->query('SELECT state FROM `' . DB_PREFIX . 'lunixi_application` WHERE order_key=' . $this->store->quote($key))->row;
            return in_array($row['state'] ?? 'pending', ['paid', 'partially_refunded'], true) && in_array((int) $order['order_status_id'], $this->orders->activeStatuses(), true);
        });
    }
    public function start(int $id, string $callback): CheckoutSession
    {
        $this->compatibility()->assertReady();
        $order = $this->orders->order($id);
        $key = $this->orders->orderKey($id);
        $credentials = $this->profileFor($key);
        $config = $this->registry->get('config');
        return $this->service($key)->start($key, $this->orders->snapshot($order, $credentials['merchantId']), $callback, $config->get('payment_lunixi_mode') === 'hosted', (bool) $config->get('payment_lunixi_preauth'), str_starts_with((string) $config->get('config_language'), 'tr') ? 'tr' : 'en');
    }
    public function reconcile(int $id, bool $rateLimit = true): ?PaymentDecision
    {
        $this->compatibility()->assertReady();
        $key = $this->orders->orderKey($id);
        $this->orders->order($id);
        if ($rateLimit && !$this->store->reservePoll($key)) {
            return null;
        }
        $decision = $this->service($key)->reconcile($key, fn(PaymentDecision $d) => $this->orders->apply($key, $d));
        $this->syncOperations($key);
        return $decision;
    }
    public function webhook(string $raw, array $headers): PaymentDecision
    {
        $this->compatibility()->assertReady();
        if (strlen($raw) > 1048576) {
            throw new \RuntimeException('Webhook is too large.');
        }
        // Unauthenticated fields locate a candidate only; they never authorize state.
        $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $data = $body['data'] ?? [];
        $key = null;
        $id = $data['intentId'] ?? $data['paymentId'] ?? $data['paymentIntentId'] ?? null;
        if (is_string($id) && strlen($id) <= 200) {
            $key = $this->store->findPayment($id, $this->storeId);
        }
        if ($key === null && is_string($data['orderId'] ?? null) && preg_match('/^oc4:' . $this->storeId . ':[1-9][0-9]*$/D', $data['orderId'])) {
            $key = $data['orderId'];
        }
        if ($key === null) {
            throw new \RuntimeException('The bound checkout is not ready; retry delivery.');
        }
        $profile = $this->profileFor($key);
        $service = $this->service($key);
        $event = $service->verifyWebhook($raw, $headers, $profile['webhookSecret']);
        $decision = $service->reconcile($key, fn(PaymentDecision $d) => $this->orders->apply($key, $d), $event);
        $this->syncOperations($key);
        return $decision;
    }
    /** Reserve a stable native operation BEFORE network I/O. No fresh operation while unresolved. */
    private function reserveOperation(int $id, string $action, ?string $decimal, ?string $operationId = null): array
    {
        $this->compatibility()->assertReady();
        $key = $this->orders->orderKey($id);
        $order = $this->orders->order($id);
        if (!in_array($action, ['capture', 'refund', 'void'], true)) {
            throw new \RuntimeException('Invalid payment operation.');
        }
        $currency = $this->store->query('SELECT decimal_place FROM `' . DB_PREFIX . 'currency` WHERE code=' . $this->store->quote($order['currency_code']))->row;
        $amount = $action === 'void' ? null : \Lunixi\OpenCart4\Vendor\Lunixi\Sdk\Commerce\Money::minor($decimal, (int) $currency['decimal_place']);
        $operationId = $this->store->transaction($key, function () use ($key, $action, $amount, $operationId): string {
            $bound = $this->store->load($key);
            if ($bound === null || ($bound['paymentId'] ?? '') === '') {
                throw new \RuntimeException('A bound payment is required for a native operation.');
            }
            $native = $this->orders->order($this->store->nativeId($key), true);
            if ($this->orders->snapshot($native, (string) $bound['snapshot']['merchantId'])->fingerprint() !== \Lunixi\OpenCart4\Vendor\Lunixi\Sdk\Commerce\OrderSnapshot::fromArray($bound['snapshot'])->fingerprint()) {
                throw new \RuntimeException('The native order changed after checkout.');
            }
            $r = $this->store->query('SELECT * FROM `' . DB_PREFIX . 'lunixi_operation` WHERE order_key=' . $this->store->quote($key) . " AND state IN ('pending','uncertain') ORDER BY created_at LIMIT 1 FOR UPDATE")->row;
            if ($r !== []) {
                if ($r['action'] !== $action || ($r['amount_minor'] === null ? null : (string) $r['amount_minor']) !== $amount || $operationId !== null && $operationId !== $r['operation_id']) {
                    throw new \RuntimeException('Retry or reconcile the unresolved operation first.');
                }
                return $r['operation_id'];
            }
            $id = $operationId ?? 'oc4-op-' . bin2hex(random_bytes(24));
            if (!preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $id)) {
                throw new \RuntimeException('Invalid native operation ID.');
            }
            $this->store->query('INSERT IGNORE INTO `' . DB_PREFIX . 'lunixi_operation` (operation_id,order_key,action,amount_minor,created_at) VALUES (' . $this->store->quote($id) . ',' . $this->store->quote($key) . ',' . $this->store->quote($action) . ',' . ($amount === null ? 'NULL' : $amount) . ',' . time() . ')');
            $saved = $this->store->query('SELECT * FROM `' . DB_PREFIX . 'lunixi_operation` WHERE operation_id=' . $this->store->quote($id))->row;
            if (($saved['order_key'] ?? null) !== $key || ($saved['action'] ?? null) !== $action || ($saved['amount_minor'] === null ? null : (string) $saved['amount_minor']) !== $amount) {
                throw new \RuntimeException('Native operation ID was reused for a different request.');
            }
            if (($saved['state'] ?? '') === 'rejected') {
                $this->store->query('UPDATE `' . DB_PREFIX . "lunixi_operation` SET state='pending' WHERE operation_id=" . $this->store->quote($id));
            }
            return $id;
        });
        return [$key, $operationId, $amount];
    }
    /** Admin returns pending; the catalog worker performs the operation in the genuine native catalog context. */
    public function queueOperation(int $id, string $action, ?string $decimal, ?string $operationId = null): array
    {
        [$key, $operationId] = $this->reserveOperation($id, $action, $decimal, $operationId);
        $row = $this->store->query('SELECT state FROM `' . DB_PREFIX . 'lunixi_operation` WHERE order_key=' . $this->store->quote($key) . ' AND operation_id=' . $this->store->quote($operationId))->row;
        return ['operationId' => $operationId, 'state' => $row['state'] ?? 'pending'];
    }
    /** Catalog/CLI execution only; UI must only show success after shared operationStatus confirms. */
    public function operation(int $id, string $action, ?string $decimal, ?string $operationId = null): array
    {
        [$key, $operationId, $amount] = $this->reserveOperation($id, $action, $decimal, $operationId);
        $service = $this->service($key);
        try {
            $service->mutate($key, $operationId, $action, $amount, fn(PaymentDecision $d) => $this->orders->apply($key, $d));
        } catch (\Throwable $error) {
            $state = $service->operationStatus($key, $operationId) === null ? 'rejected' : 'uncertain';
            $this->store->query('UPDATE `' . DB_PREFIX . 'lunixi_operation` SET state=' . $this->store->quote($state) . ' WHERE operation_id=' . $this->store->quote($operationId) . " AND state IN ('pending','uncertain')");
            return ['operationId' => $operationId, 'state' => $state];
        }
        $status = $service->operationStatus($key, $operationId);
        $state = $status['state'] ?? 'uncertain';
        $this->store->query('UPDATE `' . DB_PREFIX . 'lunixi_operation` SET state=' . $this->store->quote($state) . ' WHERE operation_id=' . $this->store->quote($operationId));
        return ['operationId' => $operationId, 'state' => $state];
    }
    private function syncOperations(string $key): void
    {
        $rows = $this->store->query('SELECT operation_id FROM `' . DB_PREFIX . 'lunixi_operation` WHERE order_key=' . $this->store->quote($key) . " AND state IN ('pending','uncertain')")->rows;
        foreach ($rows as $row) {
            $state = $this->service($key)->operationStatus($key, $row['operation_id']);
            if ($state !== null) {
                $this->store->query('UPDATE `' . DB_PREFIX . 'lunixi_operation` SET state=' . $this->store->quote($state['state']) . ' WHERE operation_id=' . $this->store->quote($row['operation_id']));
            }
        }
    }
    public function operationRows(int $id): array
    {
        return $this->store->query('SELECT operation_id,action,amount_minor,state,created_at FROM `' . DB_PREFIX . 'lunixi_operation` WHERE order_key=' . $this->store->quote($this->orders->orderKey($id)) . ' ORDER BY created_at DESC LIMIT 20')->rows;
    }
    public function runScheduled(int $limit = 50): array
    {
        $this->compatibility()->assertReady();
        $result = ['reconciled' => 0, 'pending' => 0, 'failed' => 0];
        $operations = $this->store->query('SELECT o.* FROM `' . DB_PREFIX . 'lunixi_operation` o JOIN `' . DB_PREFIX . 'lunixi_attempt` a ON a.order_key=o.order_key WHERE a.store_id=' . $this->storeId . " AND o.state IN ('pending','uncertain') ORDER BY o.created_at LIMIT " . max(1, min(50, $limit)))->rows;
        foreach ($operations as $operation) {
            try {
                $id = $this->store->nativeId($operation['order_key']);
                $order = $this->orders->order($id);
                $decimal = $operation['amount_minor'] === null ? null : \Lunixi\OpenCart4\Vendor\Lunixi\Sdk\Commerce\Money::decimal((string) $operation['amount_minor'], \Lunixi\OpenCart4\Vendor\Lunixi\Sdk\Commerce\Money::exponent($order['currency_code']));
                $state = $this->operation($id, $operation['action'], $decimal, $operation['operation_id']);
                if ($state['state'] !== 'confirmed') {
                    $result['pending']++;
                }
            } catch (\Throwable $error) {
                $result['failed']++;
            }
        }
        foreach ($this->store->pendingKeys($this->storeId, $limit) as $key) {
            try {
                if ($this->reconcile($this->store->nativeId($key)) !== null) {
                    $result['reconciled']++;
                } else {
                    $result['pending']++;
                }
            } catch (\Throwable $error) {
                $result['failed']++;
            }
        }
        return $result + $this->orders->deliverNotifications();
    }
}
