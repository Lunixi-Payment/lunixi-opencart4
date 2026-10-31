<?php

declare (strict_types=1);
use PHPUnit\Framework\TestCase;
use Lunixi\OpenCart4\{Kernel, NativeLedgerStore, Security};
use Lunixi\OpenCart4\Vendor\Lunixi\Sdk\LunixiClient;
use Lunixi\OpenCart4\Vendor\Lunixi\Sdk\Commerce\CheckoutService;
require_once __DIR__ . '/NativeGateway.php';
require_once __DIR__ . '/NativePaymentConfiguration.php';
final class NativeAcceptanceTest extends TestCase
{
    private Opencart\System\Engine\Registry $registry;
    private Kernel $kernel;
    private NativeGateway $gateway;
    private LunixiClient $client;
    public static function setUpBeforeClass(): void
    {
        putenv('LUNIXI_OPENCART_ENCRYPTION_KEY=' . base64_encode(str_repeat('t', 32)));
        $registry = nativeRegistry();
        $store = new NativeLedgerStore($registry->get('db'), DB_PREFIX);
        $store->install();
        if (getenv('LUNIXI_NATIVE_TEST_DESTRUCTIVE') !== '1') {
            throw new RuntimeException('Explicit disposable native schema mutation opt-in is required.');
        }
        $registry->get('db')->query('DROP TABLE IF EXISTS `' . DB_PREFIX . 'lunixi_native_test_probe`');
        $registry->get('db')->query('CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'lunixi_native_test_probe` (id INT PRIMARY KEY) ENGINE=MyISAM');
        $tables = $store->nonTransactionalTables();
        self::assertNotEmpty($tables, 'Any MyISAM native table must prove the safety gate.');
        try {
            $store->assertTransactional();
            self::fail('MyISAM preflight did not fail.');
        } catch (RuntimeException $expected) {
        }
        foreach ($tables as $table) {
            $registry->get('db')->query('ALTER TABLE `' . str_replace('`', '', $table) . '` ENGINE=InnoDB');
        }
        self::assertSame([], $store->nonTransactionalTables());
    }
    protected function setUp(): void
    {
        $this->registry = nativeRegistry();
        $db = $this->registry->get('db');
        foreach (['lunixi_attempt', 'lunixi_event', 'lunixi_application', 'lunixi_outbox', 'lunixi_operation', 'lunixi_profile', 'order', 'order_product', 'order_option', 'order_history', 'order_total', 'fraud_ip'] as $table) {
            $db->query('DELETE FROM `' . DB_PREFIX . $table . '`');
        }
        $db->query("UPDATE `" . DB_PREFIX . "product` SET quantity=10,subtract=1");
        $db->query("DELETE FROM `" . DB_PREFIX . "currency` WHERE code='TRY'");
        $db->query("INSERT INTO `" . DB_PREFIX . "currency` (title,code,symbol_left,symbol_right,decimal_place,value,status,date_modified) VALUES ('Turkish Lira','TRY','','TL','2','1.00000000',1,NOW()) ON DUPLICATE KEY UPDATE decimal_place='2'");
        $config = $this->registry->get('config');
        nativePaymentConfiguration($this->registry);
        $this->gateway = new NativeGateway();
        $fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/canonical-vectors.json'), true, 16, JSON_THROW_ON_ERROR);
        $this->client = LunixiClient::create(['baseUrl' => 'https://api-gateway.lunixi.com', 'keyId' => 'mk_test_native', 'privateKey' => $fixture['privateKeyPem'], 'maxRetries' => 0], $this->gateway);
        $initial = new Kernel($this->registry, $this->client);
        $profile = $initial->saveProfile(['payment_lunixi_merchant_id' => 'merchant-native', 'payment_lunixi_key_id' => 'mk_test_native', 'payment_lunixi_private_key' => $fixture['privateKeyPem'], 'payment_lunixi_webhook_secret' => 'synthetic_webhook_secret_0123456789']);
        $config->set('payment_lunixi_profile_id', $profile);
        $this->kernel = new Kernel($this->registry, $this->client);
    }
    private function order(string $total = '10.05', string $rate = '1.00000000'): int
    {
        $this->registry->get('load')->model('checkout/order');
        $db = $this->registry->get('db');
        $product = (int) $db->query('SELECT product_id FROM `' . DB_PREFIX . 'product` ORDER BY product_id LIMIT 1')->row['product_id'];
        $data = ['subscription_id' => 0, 'payment_address_id' => 0, 'shipping_address_id' => 0, 'language_code' => 'en-gb', 'invoice_prefix' => 'INV-TEST-', 'store_id' => 0, 'store_name' => 'Synthetic', 'store_url' => 'https://merchant.example/', 'customer_id' => 0, 'customer_group_id' => 1, 'firstname' => 'Synthetic', 'lastname' => 'Customer', 'email' => 'synthetic@example.invalid', 'telephone' => '0000000000', 'custom_field' => [], 'payment_firstname' => 'Synthetic', 'payment_lastname' => 'Customer', 'payment_company' => '', 'payment_address_1' => 'Synthetic', 'payment_address_2' => '', 'payment_city' => 'Istanbul', 'payment_postcode' => '34000', 'payment_country' => 'Turkey', 'payment_country_id' => 215, 'payment_zone' => 'Istanbul', 'payment_zone_id' => 3354, 'payment_address_format' => '', 'payment_custom_field' => [], 'payment_method' => ['name' => 'Lunixi', 'code' => 'lunixi.lunixi'], 'shipping_firstname' => 'Synthetic', 'shipping_lastname' => 'Customer', 'shipping_company' => '', 'shipping_address_1' => 'Synthetic', 'shipping_address_2' => '', 'shipping_city' => 'Istanbul', 'shipping_postcode' => '34000', 'shipping_country' => 'Turkey', 'shipping_country_id' => 215, 'shipping_zone' => 'Istanbul', 'shipping_zone_id' => 3354, 'shipping_address_format' => '', 'shipping_custom_field' => [], 'shipping_method' => [], 'comment' => '', 'total' => $total, 'affiliate_id' => 0, 'commission' => '0', 'marketing_id' => 0, 'tracking' => '', 'language_id' => 1, 'currency_id' => 1, 'currency_code' => 'TRY', 'currency_value' => $rate, 'ip' => '203.0.113.8', 'forwarded_ip' => '', 'user_agent' => 'Synthetic test', 'accept_language' => 'en-gb', 'products' => [['master_id' => 0, 'product_id' => $product, 'name' => 'Synthetic product', 'model' => 'test', 'quantity' => 1, 'price' => $total, 'total' => $total, 'tax' => 0, 'reward' => 0, 'option' => [], 'download' => []]], 'vouchers' => [], 'totals' => [['extension' => 'opencart', 'code' => 'total', 'title' => 'Total', 'value' => $total, 'sort_order' => 9]]];
        return $this->registry->get('model_checkout_order')->addOrder($data);
    }
    private function event(string $id, string $paymentId, string $orderKey, string $type = 'payment.captured'): array
    {
        $raw = json_encode(['id' => $id, 'type' => $type, 'data' => ['intentId' => $paymentId, 'orderId' => $orderKey, 'capturedAmountMinor' => '1005']], JSON_THROW_ON_ERROR);
        $timestamp = gmdate('Y-m-d\TH:i:s.000\Z');
        $sig = hash_hmac('sha256', $id . '.' . $timestamp . '.' . $raw, 'synthetic_webhook_secret_0123456789');
        return [$raw, ['x-lunixi-event-id' => $id, 'x-lunixi-signature-timestamp' => $timestamp, 'x-lunixi-signature' => 'v2=' . $sig]];
    }
    private function stock(): int
    {
        return (int) $this->registry->get('db')->query('SELECT quantity FROM `' . DB_PREFIX . 'product` ORDER BY product_id LIMIT 1')->row['quantity'];
    }
    public function testScopedMerchantArtifactAndNativePreflight(): void
    {
        self::assertTrue(class_exists(LunixiClient::class));
        self::assertFalse(class_exists('Lunixi\Sdk\LunixiClient', false));
        self::assertSame([], $this->kernel->compatibility()->report());
        $id = $this->order('199.9500', '1.25000000');
        $order = $this->kernel->orders()->order($id);
        self::assertIsString($order['total']);
        self::assertSame(24994, $this->kernel->orders()->snapshot($order, 'merchant-native')->amount());
        $cipher = $this->registry->get('db')->query('SELECT ciphertext FROM `' . DB_PREFIX . 'lunixi_profile`')->row['ciphertext'];
        self::assertStringNotContainsString('PRIVATE KEY', $cipher);
        self::assertStringNotContainsString('synthetic_webhook', $cipher);
        $this->registry->get('db')->query("UPDATE `" . DB_PREFIX . "currency` SET decimal_place='3' WHERE code='TRY'");
        $this->expectException(RuntimeException::class);
        $this->kernel->orders()->snapshot($order, 'merchant-native');
    }
    public function testNativeStockRollbackSignedReplayCollisionAndHistory(): void
    {
        $id = $this->order();
        $session = $this->kernel->start($id, 'https://merchant.example/return');
        self::assertSame(10, $this->stock());
        self::assertSame($this->gateway->paymentPageUrl, $session->hostedUrl());
        $key = $this->kernel->orders()->orderKey($id);
        [$raw, $headers] = $this->event('evt-native', $session->paymentId, $key);
        $service = new CheckoutService($this->client, $this->kernel->store(), Kernel::secretBox());
        $event = $service->verifyWebhook($raw, $headers, 'synthetic_webhook_secret_0123456789');
        try {
            $service->reconcile($key, function ($d) use ($key) {
                $this->kernel->orders()->apply($key, $d);
                throw new RuntimeException('Synthetic crash after native stock/history');
            }, $event);
            self::fail('Failed native transaction committed.');
        } catch (RuntimeException $expected) {
        }
        self::assertSame(10, $this->stock());
        self::assertSame('0', $this->registry->get('db')->query('SELECT COUNT(*) AS total FROM `' . DB_PREFIX . 'lunixi_event`')->row['total']);
        self::assertTrue($this->kernel->webhook($raw, $headers)->isPaid());
        self::assertSame(9, $this->stock());
        $this->kernel->webhook($raw, $headers);
        self::assertSame(9, $this->stock());
        self::assertSame('1', $this->registry->get('db')->query('SELECT COUNT(*) AS total FROM `' . DB_PREFIX . 'order_history` WHERE order_id=' . $id)->row['total']);
        self::assertSame('1', $this->registry->get('db')->query('SELECT COUNT(*) AS total FROM `' . DB_PREFIX . 'lunixi_outbox`')->row['total']);
        self::assertSame('0', $this->kernel->orders()->order($id)['invoice_no']);
        [$changed, $changedHeaders] = $this->event('evt-native', $session->paymentId, $key, 'payment.failed');
        try {
            $this->kernel->webhook($changed, $changedHeaders);
            self::fail('Accepted event ID collision.');
        } catch (RuntimeException $expected) {
        }
        self::assertSame(9, $this->stock());
    }
    public function testNativeFraudHoldsActuallyCapturedFundsWithoutStock(): void
    {
        $id = $this->order();
        $this->registry->get('config')->set('fraud_ip_status', 1);
        $this->registry->get('config')->set('fraud_ip_order_status_id', 8);
        $this->registry->get('db')->query("INSERT INTO `" . DB_PREFIX . "fraud_ip` SET ip='203.0.113.8'");
        $session = $this->kernel->start($id, 'https://merchant.example/return');
        $decision = $this->kernel->reconcile($id, false);
        self::assertTrue($decision->isPaid());
        self::assertSame('8', $this->kernel->orders()->order($id)['order_status_id']);
        self::assertSame(10, $this->stock());
        $this->kernel->reconcile($id, false);
        self::assertSame(10, $this->stock());
        self::assertSame('8', $this->kernel->orders()->order($id)['order_status_id']);
    }
    public function testQueuedCaptureDelayedConfirmationPartialRefundAndNativeRestock(): void
    {
        $this->registry->get('config')->set('payment_lunixi_preauth', 1);
        $id = $this->order();
        $session = $this->kernel->start($id, 'https://merchant.example/return');
        $decision = $this->kernel->reconcile($id, false);
        self::assertSame('authorized', $decision->state);
        self::assertSame(10, $this->stock());
        $queued = $this->kernel->queueOperation($id, 'capture', '10.05');
        self::assertSame('pending', $queued['state']);
        self::assertSame([], $this->gateway->keys);
        $this->gateway->deferMutation = true;
        $pending = $this->kernel->operation($id, 'capture', '10.05', $queued['operationId']);
        self::assertSame('pending', $pending['state']);
        self::assertSame(10, $this->stock());
        try {
            $this->kernel->queueOperation($id, 'refund', '1.00');
            self::fail('Fresh operation bypassed unresolved capture.');
        } catch (RuntimeException $expected) {
        }
        $this->gateway->payments[$session->paymentId]['data']['status'] = 'CAPTURED';
        $this->gateway->payments[$session->paymentId]['details']['capturedAmount'] = '1005';
        $this->kernel->reconcile($id, false);
        self::assertSame(9, $this->stock());
        self::assertSame('confirmed', $this->kernel->operationRows($id)[0]['state']);
        $this->gateway->deferMutation = false;
        $refund = $this->kernel->operation($id, 'refund', '1.00');
        self::assertSame('confirmed', $refund['state']);
        self::assertSame(9, $this->stock());
        $refund = $this->kernel->operation($id, 'refund', '9.05');
        self::assertSame('confirmed', $refund['state']);
        self::assertSame(10, $this->stock());
        self::assertSame('11', $this->kernel->orders()->order($id)['order_status_id']);
    }
    public function testLostCreateResponseRecoversOnlySignedAttemptBoundWebhook(): void
    {
        $id = $this->order();
        $this->gateway->lostCreate = true;
        try {
            $this->kernel->start($id, 'https://merchant.example/return');
            self::fail('Lost response treated as successful checkout.');
        } catch (Throwable $expected) {
        }
        $key = $this->kernel->orders()->orderKey($id);
        [$raw, $headers] = $this->event('evt-recovery', 'pi-native-0', $key);
        self::assertTrue($this->kernel->webhook($raw, $headers)->isPaid());
        self::assertSame(9, $this->stock());
    }
    public function testHostedAuthorizationVoidAndBrowserCannotCompleteChangedOrder(): void
    {
        $this->registry->get('config')->set('payment_lunixi_mode', 'hosted');
        $this->registry->get('config')->set('payment_lunixi_preauth', 1);
        $id = $this->order();
        $session = $this->kernel->start($id, 'https://merchant.example/return');
        self::assertSame($this->gateway->paymentPageUrl, $session->hostedUrl());
        self::assertSame('authorized', $this->kernel->reconcile($id, false)->state);
        self::assertFalse($this->kernel->browserComplete($id));
        $void = $this->kernel->operation($id, 'void', null);
        self::assertSame('confirmed', $void['state']);
        self::assertSame(10, $this->stock());
        self::assertFalse($this->kernel->browserComplete($id));
    }
    public function testBackendHostedUrlIsEncryptedCachedAndBoundToOriginalMode(): void
    {
        $this->registry->get('config')->set('payment_lunixi_mode', 'hosted');
        $id = $this->order();
        $key = $this->kernel->orders()->orderKey($id);
        $session = $this->kernel->start($id, 'https://merchant.example/return');
        self::assertSame($this->gateway->paymentPageUrl, $session->hostedUrl());
        self::assertNotSame('https://pay.lunixi.com/payment/' . $session->token, $session->hostedUrl());
        $record = $this->kernel->store()->transaction($key, fn() => $this->kernel->store()->load($key));
        self::assertStringNotContainsString('server-issued-page', $record['paymentPageUrl']);
        $requests = count($this->gateway->requests);
        $this->gateway->paymentPageUrl = 'https://evil.invalid/payment/server-issued-page-0123456789';
        self::assertSame($session->hostedUrl(), $this->kernel->start($id, 'https://merchant.example/return')->hostedUrl());
        self::assertSame($requests, count($this->gateway->requests));
        $this->registry->get('config')->set('payment_lunixi_mode', 'embedded');
        try {
            $this->kernel->start($id, 'https://merchant.example/return');
            self::fail('Changed presentation replaced a frozen attempt.');
        } catch (\RuntimeException $expected) {
        }
        $after = $this->kernel->store()->transaction($key, fn() => $this->kernel->store()->load($key));
        self::assertSame($record['key'], $after['key']);
        self::assertSame($record['requestHash'], $after['requestHash']);
        self::assertSame($requests, count($this->gateway->requests));
    }
    public function testMissingOrUnsafeBackendHostedUrlCannotBecomeARedirect(): void
    {
        $this->registry->get('config')->set('payment_lunixi_mode', 'hosted');
        foreach ([null, 'https://pay.lunixi.com.evil.invalid/payment/server-issued-page-0123456789', 'https://pay.lunixi.com/payment/server-issued-page-0123456789?next=evil'] as $url) {
            $this->gateway->paymentPageUrl = $url;
            $id = $this->order();
            try {
                $this->kernel->start($id, 'https://merchant.example/return')->hostedUrl();
                self::fail('Missing or unsafe hosted URL was accepted.');
            } catch (\RuntimeException $expected) {
            }
            self::assertSame(10, $this->stock());
            $key = $this->kernel->orders()->orderKey($id);
            $record = $this->kernel->store()->transaction($key, fn() => $this->kernel->store()->load($key));
            self::assertNotEmpty($record['key']);
            self::assertNotSame('paid', $record['state']);
        }
    }
    public function testSignedTamperUnknownHooksAndFrozenSnapshotFailClosed(): void
    {
        $id = $this->order();
        $session = $this->kernel->start($id, 'https://merchant.example/return');
        $key = $this->kernel->orders()->orderKey($id);
        [$raw, $headers] = $this->event('evt-tamper', $session->paymentId, $key);
        try {
            $this->kernel->webhook($raw . ' ', $headers);
            self::fail('Raw body tamper accepted.');
        } catch (Lunixi\OpenCart4\Vendor\Lunixi\Sdk\Exception\WebhookVerificationException $expected) {
        }
        self::assertSame(10, $this->stock());
        $this->kernel->webhook($raw, $headers);
        self::assertTrue($this->kernel->browserComplete($id));
        $this->registry->get('db')->query("UPDATE `" . DB_PREFIX . "order` SET total='11.0000' WHERE order_id=" . $id);
        self::assertFalse($this->kernel->browserComplete($id));
        $this->registry->get('event')->register('model/checkout/*/before', new Opencart\System\Engine\Action('extension/unknown/business'), 0);
        self::assertNotEmpty($this->kernel->compatibility()->report());
    }
    public function testUnsafeRefundIsRejectedBeforeNetworkAndDoesNotBlockValidRefund(): void
    {
        $id = $this->order();
        $this->kernel->start($id, 'https://merchant.example/return');
        $this->kernel->reconcile($id, false);
        $before = count($this->gateway->keys);
        $invalid = $this->kernel->operation($id, 'refund', '10.06');
        self::assertSame('rejected', $invalid['state']);
        self::assertCount($before, $this->gateway->keys);
        self::assertSame('confirmed', $this->kernel->operation($id, 'refund', '1.00')['state']);
    }
    public function testConcurrentProcessesConsumeEventAndNativeStockExactlyOnce(): void
    {
        $id = $this->order();
        $session = $this->kernel->start($id, 'https://merchant.example/return');
        [$raw, $headers] = $this->event('evt-parallel', $session->paymentId, $this->kernel->orders()->orderKey($id));
        $input = tempnam(sys_get_temp_dir(), 'lunixi-replay-');
        chmod($input, 0600);
        file_put_contents($input, json_encode(['payments' => $this->gateway->payments, 'raw' => $raw, 'headers' => $headers], JSON_THROW_ON_ERROR));
        putenv('LUNIXI_REPLAY_INPUT=' . $input);
        $children = [];
        try {
            for ($i = 0; $i < 3; $i++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __DIR__ . '/replay-child.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $children[] = [$process, $pipes];
            }
            foreach ($children as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $error . $output);
                self::assertSame("ok\n", $output);
            }
            self::assertSame(9, $this->stock());
            self::assertSame('1', $this->registry->get('db')->query('SELECT COUNT(*) AS total FROM `' . DB_PREFIX . 'lunixi_event`')->row['total']);
            self::assertSame('1', $this->registry->get('db')->query('SELECT COUNT(*) AS total FROM `' . DB_PREFIX . 'order_history` WHERE order_id=' . $id)->row['total']);
        } finally {
            unlink($input);
            putenv('LUNIXI_REPLAY_INPUT');
        }
    }
}
