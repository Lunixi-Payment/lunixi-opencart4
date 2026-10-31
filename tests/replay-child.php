<?php

declare (strict_types=1);
require __DIR__ . '/native_bootstrap.php';
require __DIR__ . '/NativeGateway.php';
putenv('LUNIXI_OPENCART_ENCRYPTION_KEY=' . base64_encode(str_repeat('t', 32)));
$input = json_decode(file_get_contents(getenv('LUNIXI_REPLAY_INPUT')), true, 64, JSON_THROW_ON_ERROR);
$gateway = new NativeGateway();
$gateway->payments = $input['payments'];
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/canonical-vectors.json'), true, 16, JSON_THROW_ON_ERROR);
$client = \Lunixi\OpenCart4\Vendor\Lunixi\Sdk\LunixiClient::create(['baseUrl' => 'https://api-gateway.lunixi.com', 'keyId' => 'mk_test_native', 'privateKey' => $fixture['privateKeyPem'], 'maxRetries' => 0], $gateway);
$kernel = new \Lunixi\OpenCart4\Kernel(nativeRegistry(), $client);
if (!$kernel->webhook($input['raw'], $input['headers'])->isPaid()) {
    throw new RuntimeException('Concurrent replay lost paid state.');
}
echo "ok\n";
