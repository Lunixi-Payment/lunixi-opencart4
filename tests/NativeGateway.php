<?php

declare (strict_types=1);
use Lunixi\OpenCart4\Vendor\Lunixi\Sdk\Http\{HttpClientInterface, HttpResponse};
use Lunixi\OpenCart4\Vendor\Lunixi\Sdk\Exception\ApiException;
/** Offline gateway boundary only. All OpenCart models/DB/events are the official real implementation. */
final class NativeGateway implements HttpClientInterface
{
    public ?string $paymentPageUrl = 'https://pay.lunixi.com/payment/server-issued-page-0123456789';
    public array $payments = [];
    public array $requests = [];
    public bool $lostCreate = false;
    public bool $lostMutation = false;
    public bool $deferMutation = false;
    public array $keys = [];
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $value = $body === null ? null : json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $this->requests[] = [$method, $path, $headers, $value];
        if ($path === '/api/v1/auth/token') {
            return $this->json(200, ['access_token' => 'at_TEST_native', 'expires_in' => 1800]);
        }
        if ($path === '/api/v1/payments/intents') {
            $id = 'pi-native-' . count($this->payments);
            $amount = $value['amount'];
            $p = ['data' => ['id' => $id, 'merchantId' => 'merchant-native', 'orderId' => $value['orderId'], 'amount' => $amount, 'currency' => $value['currency'], 'status' => $value['preauth'] ?? false ? 'AUTHORIZED' : 'CAPTURED', 'metadata' => $value['metadata']], 'details' => ['capturedAmount' => $value['preauth'] ?? false ? '0' : (string) $amount, 'refundedAmount' => '0']];
            $this->payments[$id] = $p;
            if ($this->lostCreate) {
                $this->lostCreate = false;
                throw new ApiException('Synthetic lost create response', 0);
            }
            $result = ['paymentId' => $id, 'token' => 'synthetic-checkout-token'];
            if ($this->paymentPageUrl !== null) {
                $result['paymentPageUrl'] = $this->paymentPageUrl;
            }
            return $this->json(201, ['data' => $result]);
        }
        if (preg_match('~/api/v1/payments/([^/]+)(?:/(capture|refund|void))?$~', $path, $match)) {
            $id = $match[1];
            if (!isset($this->payments[$id])) {
                return $this->json(404, ['code' => 'NOT_FOUND']);
            }
            if ($method === 'GET') {
                return $this->json(200, $this->payments[$id]);
            }
            $key = $headers['Idempotency-Key'] ?? $headers['idempotency-key'] ?? '';
            $this->keys[] = $key;
            if (!$this->deferMutation) {
                $p =& $this->payments[$id];
                $action = $match[2];
                $amount = $value['amount'] ?? null;
                if ($action === 'capture') {
                    $p['details']['capturedAmount'] = (string) ((int) $p['details']['capturedAmount'] + $amount);
                    $p['data']['status'] = (int) $p['details']['capturedAmount'] === $p['data']['amount'] ? 'CAPTURED' : 'PARTIALLY_CAPTURED';
                }
                if ($action === 'refund') {
                    $p['details']['refundedAmount'] = (string) ((int) $p['details']['refundedAmount'] + $amount);
                    $p['data']['status'] = $p['details']['refundedAmount'] === $p['details']['capturedAmount'] ? 'REFUNDED' : 'PARTIALLY_REFUNDED';
                }
                if ($action === 'void') {
                    $p['data']['status'] = 'VOIDED';
                }
            }
            if ($this->lostMutation) {
                $this->lostMutation = false;
                throw new ApiException('Synthetic lost mutation response', 0);
            }
            return $this->json(202, ['data' => ['accepted' => true]]);
        }
        return $this->json(404, ['code' => 'NOT_FOUND']);
    }
    private function json(int $status, array $data): HttpResponse
    {
        return new HttpResponse($status, ['content-type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }
}
