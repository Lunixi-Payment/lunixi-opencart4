<?php

declare (strict_types=1);
namespace Opencart\Catalog\Controller\Extension\Lunixi\Payment;

/**
 * Native services exposed by the platform registry.
 * @property \Opencart\System\Engine\Loader $load
 * @property \Opencart\System\Engine\Config $config
 * @property \Opencart\System\Library\Url $url
 * @property \Opencart\System\Library\Cart\User $user
 * @property \Opencart\System\Library\Request $request
 * @property \Opencart\System\Library\Response $response
 * @property \Opencart\System\Library\Session $session
 * @property \Opencart\System\Library\Language $language
 * @property \Opencart\System\Library\Document $document
 * @property \Opencart\System\Library\Cart\Cart $cart
 */
class Lunixi extends \Opencart\System\Engine\Controller
{
    private function kernel()
    {
        require_once DIR_EXTENSION . 'lunixi/system/library/lunixi4/autoload.php';
        return new \Lunixi\OpenCart4\Kernel($this->registry);
    }
    private function link($method, $query = '')
    {
        return html_entity_decode($this->url->link('extension/lunixi/payment/lunixi.' . $method, $query, true), ENT_QUOTES, 'UTF-8');
    }
    public function index()
    {
        $this->load->language('extension/lunixi/payment/lunixi');
        if (empty($this->session->data['lunixi_checkout_csrf'])) {
            $this->session->data['lunixi_checkout_csrf'] = bin2hex(random_bytes(24));
        }
        $data = $this->language->all();
        $data['lunixi_csrf'] = $this->session->data['lunixi_checkout_csrf'];
        $data['confirm_json'] = json_encode($this->link('confirm'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $data['status_json'] = json_encode($this->link('status'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $data['csp_nonce'] = (string) ($this->config->get('lunixi_csp_nonce') ?? '');
        return $this->load->view('extension/lunixi/payment/lunixi', $data);
    }
    public function confirm()
    {
        $this->load->language('extension/lunixi/payment/lunixi');
        try {
            $id = (int) ($this->session->data['order_id'] ?? 0);
            $kernel = $this->kernel();
            \Lunixi\OpenCart4\Security::customer($this->registry, $id, true);
            if (!$this->config->get('payment_lunixi_status')) {
                throw new \RuntimeException('Payment method is disabled.');
            }
            $session = $kernel->start($id, $this->link('returned', 'order_id=' . $id));
            $json = $this->config->get('payment_lunixi_mode') === 'hosted' ? ['redirect' => $session->hostedUrl()] : ['config' => json_decode($session->browserConfig(), true, 16, JSON_THROW_ON_ERROR)];
            $this->json($json);
        } catch (\Throwable $error) {
            $this->json(['error' => $this->language->get('error_checkout')], 409);
        }
    }
    public function status()
    {
        $this->load->language('extension/lunixi/payment/lunixi');
        try {
            $id = (int) ($this->session->data['order_id'] ?? 0);
            $kernel = $this->kernel();
            \Lunixi\OpenCart4\Security::customer($this->registry, $id);
            $decision = $kernel->reconcile($id);
            $paid = $kernel->browserComplete($id);
            $this->json($paid ? ['complete' => true, 'redirect' => html_entity_decode($this->url->link('checkout/success', '', true), ENT_QUOTES, 'UTF-8')] : ['complete' => false, 'message' => $this->language->get('text_pending')]);
        } catch (\Throwable $error) {
            $this->json(['complete' => false, 'message' => $this->language->get('text_pending')], 202);
        }
    }
    public function returned()
    {
        // Lunixi returns with POST. A cross-site POST may omit the SameSite=Lax
        // session cookie, so this informational hop must not require ownership,
        // read the body, construct the gateway client, or change an order.
        if (($this->request->server['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->response->addHeader('Cache-Control: no-store');
            $length = $this->request->server['CONTENT_LENGTH'] ?? '0';
            if (!is_scalar($length) || !ctype_digit((string) $length) || (int) $length > 65536) {
                $this->response->addHeader('HTTP/1.1 413 Content Too Large');
                $this->response->setOutput('Callback request too large.');
                return;
            }
            $candidate = $this->request->get['order_id'] ?? '';
            $id = is_string($candidate) && ctype_digit($candidate) && strlen($candidate) <= 10 ? (int) $candidate : 0;
            // A relative Location cannot inherit an attacker-supplied Host or URL.
            // The GET below retains the native session/customer/order checks.
            header('Cache-Control: no-store');
            $this->response->redirect('?route=extension/lunixi/payment/lunixi.returned&order_id=' . $id, 303);
            return;
        }
        // Browser-supplied success/status/payment IDs are deliberately ignored.
        $this->load->language('extension/lunixi/payment/lunixi');
        $this->response->addHeader('Cache-Control: no-store');
        try {
            $id = (int) ($this->request->get['order_id'] ?? 0);
            $kernel = $this->kernel();
            \Lunixi\OpenCart4\Security::customer($this->registry, $id);
            $decision = $kernel->reconcile($id, false);
            $order = $kernel->orders()->order($id);
            if ($decision !== null && $decision->isPaid() && in_array((int) $order['order_status_id'], $kernel->orders()->activeStatuses(), true)) {
                $this->response->redirect($this->url->link('checkout/success', '', true));
                return;
            }
        } catch (\Throwable $error) {
        }
        $data = $this->language->all();
        $data['header'] = $this->load->controller('common/header');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/lunixi/payment/lunixi_wait', $data));
    }
    public function webhook()
    {
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->addHeader('HTTP/1.1 405 Method Not Allowed');
            return;
        }
        try {
            $raw = file_get_contents('php://input', false, null, 0, 1048577);
            if (!is_string($raw) || strlen($raw) > 1048576) {
                throw new \RuntimeException('Invalid webhook size.');
            }
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $this->kernel()->webhook($raw, $headers);
            $this->response->addHeader('HTTP/1.1 204 No Content');
            $this->response->setOutput('');
        } catch (\Throwable $error) {
            $this->response->addHeader('HTTP/1.1 503 Service Unavailable');
            $this->response->setOutput('Retry delivery.');
        }
    }
    public function cron()
    {
        if (PHP_SAPI !== 'cli') {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            return;
        }
        try {
            $this->json($this->kernel()->runScheduled(50));
        } catch (\Throwable $error) {
            $this->json(['error' => 'reconciliation_unavailable'], 503);
        }
    }
    private function json(array $data, int $status = 200)
    {
        if ($status !== 200) {
            $this->response->addHeader('HTTP/1.1 ' . $status . ' Lunixi');
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->setOutput(json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    }
}
