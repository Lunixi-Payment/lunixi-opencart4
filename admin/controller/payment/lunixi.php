<?php

declare (strict_types=1);
namespace Opencart\Admin\Controller\Extension\Lunixi\Payment;

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
 * @property \Opencart\Admin\Model\Localisation\OrderStatus $model_localisation_order_status
 * @property \Opencart\Admin\Model\Localisation\Language $model_localisation_language
 * @property \Opencart\Admin\Model\Setting\Setting $model_setting_setting
 * @property \Opencart\Admin\Model\Setting\Store $model_setting_store
 */
class Lunixi extends \Opencart\System\Engine\Controller
{
    private bool $lunixiStoreSelected = false;
    private function kernel()
    {
        require_once DIR_EXTENSION . 'lunixi/system/library/lunixi4/autoload.php';
        if (!$this->lunixiStoreSelected) {
            \Lunixi\OpenCart4\StoreContext::select($this->registry, (int) ($this->request->get['store_id'] ?? 0));
            $this->lunixiStoreSelected = true;
        }
        return new \Lunixi\OpenCart4\Kernel($this->registry);
    }
    private function link($route, $args = '', $secure = true)
    {
        return html_entity_decode($this->url->link($route, $args, $secure), ENT_QUOTES, 'UTF-8');
    }
    public function index()
    {
        $this->load->language('extension/lunixi/payment/lunixi');
        $this->kernel();
        if (!$this->user->hasPermission('access', 'extension/lunixi/payment/lunixi')) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            return;
        }
        $this->document->setTitle($this->language->get('heading_title'));
        $data = $this->language->all();
        $data['csp_nonce'] = (string) ($this->config->get('lunixi_csp_nonce') ?? '');
        if (empty($this->session->data['lunixi_admin_csrf'])) {
            $this->session->data['lunixi_admin_csrf'] = bin2hex(random_bytes(24));
        }
        $data['lunixi_csrf'] = $this->session->data['lunixi_admin_csrf'];
        $data['save'] = $this->link('extension/lunixi/payment/lunixi.save', 'store_id=' . (int) $this->config->get('config_store_id') . '&user_token=' . $this->session->data['user_token'], true);
        $data['operate'] = $this->link('extension/lunixi/payment/lunixi.operate', 'store_id=' . (int) $this->config->get('config_store_id') . '&user_token=' . $this->session->data['user_token'], true);
        $data['refresh'] = $this->link('extension/lunixi/payment/lunixi.operations', 'store_id=' . (int) $this->config->get('config_store_id') . '&user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->link('marketplace/extension', 'store_id=' . (int) $this->config->get('config_store_id') . '&user_token=' . $this->session->data['user_token'] . '&type=payment', true);
        $defaults = ['status' => 0, 'mode' => 'embedded', 'preauth' => 0, 'sort_order' => 0, 'paid_status_id' => 2, 'pending_status_id' => 1, 'authorized_status_id' => 1, 'review_status_id' => 1, 'failed_status_id' => 10, 'refunded_status_id' => 11];
        foreach ($defaults as $key => $default) {
            $value = $this->config->get('payment_lunixi_' . $key);
            $data['payment_lunixi_' . $key] = $value === null ? $default : $value;
        }
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['compatibility'] = [];
        try {
            $kernel = $this->kernel();
            $data['compatibility'] = $kernel->compatibility()->report();
            \Lunixi\OpenCart4\Kernel::secretBox();
            $data['gateway'] = \Lunixi\OpenCart4\Kernel::origin();
        } catch (\Throwable $error) {
            $data['compatibility'][] = 'server_key_or_installation';
            $data['gateway'] = 'https://api-gateway.lunixi.com';
        }
        $data['configured'] = (bool) $this->config->get('payment_lunixi_profile_id');
        $data['store_id'] = (int) $this->config->get('config_store_id');
        $this->load->model('setting/store');
        $data['stores'] = array_merge([['store_id' => 0, 'name' => $this->language->get('text_default_store')]], $this->model_setting_store->getStores());
        $data['store_select'] = $this->link('extension/lunixi/payment/lunixi', 'user_token=' . $this->session->data['user_token'], true);
        $data['webhook'] = \Lunixi\OpenCart4\StoreContext::url($this->registry) . 'index.php?route=extension/lunixi/payment/lunixi.webhook';
        $data['logo'] = rtrim(HTTP_CATALOG, '/') . '/extension/lunixi/catalog/view/image/lunixi.svg';
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/lunixi/payment/lunixi', $data));
    }
    public function save()
    {
        $this->load->language('extension/lunixi/payment/lunixi');
        try {
            $kernel = $this->kernel();
            \Lunixi\OpenCart4\Security::admin($this->registry);
            $post = $this->request->post;
            $settings = [];
            foreach (['status', 'preauth'] as $field) {
                $settings['payment_lunixi_' . $field] = isset($post['payment_lunixi_' . $field]) && $post['payment_lunixi_' . $field] === '1' ? 1 : 0;
            }
            $mode = $post['payment_lunixi_mode'] ?? 'embedded';
            if (!in_array($mode, ['embedded', 'hosted'], true)) {
                throw new \RuntimeException('Invalid mode.');
            }
            $settings['payment_lunixi_mode'] = $mode;
            $settings['payment_lunixi_sort_order'] = (int) ($post['payment_lunixi_sort_order'] ?? 0);
            $active = $kernel->orders()->activeStatuses();
            foreach (['paid', 'pending', 'authorized', 'review', 'failed', 'refunded'] as $state) {
                $id = (int) ($post['payment_lunixi_' . $state . '_status_id'] ?? 0);
                $row = $kernel->store()->query('SELECT order_status_id FROM `' . DB_PREFIX . 'order_status` WHERE order_status_id=' . $id . ' LIMIT 1')->row;
                if (!$row || ($state === 'paid') !== in_array($id, $active, true)) {
                    throw new \RuntimeException('Unsafe native order status.');
                }
                $settings['payment_lunixi_' . $state . '_status_id'] = $id;
            }
            if ($settings['payment_lunixi_status']) {
                $kernel->compatibility()->assertReady();
            }
            $settings['payment_lunixi_profile_id'] = $kernel->saveProfile($post);
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_lunixi', $settings, (int) $this->config->get('config_store_id'));
            $this->json(['success' => $this->language->get('text_saved')]);
        } catch (\Throwable $error) {
            $this->json(['error' => $this->language->get('error_configuration')], 400);
        }
    }
    public function operate()
    {
        $this->load->language('extension/lunixi/payment/lunixi');
        try {
            $kernel = $this->kernel();
            \Lunixi\OpenCart4\Security::admin($this->registry, true);
            $id = (int) ($this->request->post['order_id'] ?? 0);
            $action = (string) ($this->request->post['operation'] ?? '');
            $decimal = $action === 'void' ? null : (is_string($this->request->post['amount'] ?? null) ? $this->request->post['amount'] : null);
            $operationId = $this->request->post['operation_id'] ?? null;
            $result = $kernel->queueOperation($id, $action, $decimal, is_string($operationId) && $operationId !== '' ? $operationId : null);
            $this->json(['state' => $result['state'], 'operation_id' => $result['operationId'], 'message' => $this->language->get($result['state'] === 'confirmed' ? 'text_confirmed' : 'text_queued')], $result['state'] === 'confirmed' ? 200 : 202);
        } catch (\Throwable $error) {
            $this->json(['error' => $this->language->get('error_operation')], 409);
        }
    }
    public function operations()
    {
        $this->load->language('extension/lunixi/payment/lunixi');
        try {
            $kernel = $this->kernel();
            \Lunixi\OpenCart4\Security::admin($this->registry, true);
            $this->json(['operations' => $kernel->operationRows((int) ($this->request->post['order_id'] ?? 0))]);
        } catch (\Throwable $error) {
            $this->json(['error' => $this->language->get('error_permission')], 403);
        }
    }
    public function install()
    {
        $this->load->language('extension/lunixi/payment/lunixi');
        $kernel = $this->kernel();
        \Lunixi\OpenCart4\Security::admin($this->registry, false, false, false);
        $kernel->store()->install();
        $this->load->model('localisation/order_status');
        $this->load->model('localisation/language');
        $this->load->model('setting/setting');
        $defaults = ['payment_lunixi_status' => 0, 'payment_lunixi_mode' => 'embedded', 'payment_lunixi_preauth' => 0, 'payment_lunixi_sort_order' => 0, 'payment_lunixi_paid_status_id' => (int) ($kernel->orders()->activeStatuses()[0] ?? 2), 'payment_lunixi_failed_status_id' => 10, 'payment_lunixi_refunded_status_id' => 11];
        foreach (['pending', 'authorized', 'review'] as $state) {
            $id = (int) $kernel->store()->meta('status:' . $state);
            if ($id < 1 || !$this->model_localisation_order_status->getOrderStatus($id)) {
                $names = [];
                foreach ($this->model_localisation_language->getLanguages() as $language) {
                    $translation = new \Opencart\System\Library\Language($language['code']);
                    $translation->addPath(DIR_LANGUAGE);
                    $translation->addPath('extension/lunixi', DIR_EXTENSION . 'lunixi/admin/language/');
                    $translation->load('extension/lunixi/payment/lunixi');
                    $names[(int) $language['language_id']] = ['name' => $translation->get('status_' . $state)];
                }
                $id = (int) $this->model_localisation_order_status->addOrderStatus(['order_status' => $names]);
                $kernel->store()->putMeta('status:' . $state, (string) $id);
            }
            $defaults['payment_lunixi_' . $state . '_status_id'] = $id;
        }
        $existing = $this->model_setting_setting->getSetting('payment_lunixi', (int) $this->config->get('config_store_id'));
        $this->model_setting_setting->editSetting('payment_lunixi', array_replace($defaults, $existing), (int) $this->config->get('config_store_id'));
    }
    public function uninstall()
    {
        $this->kernel();
        \Lunixi\OpenCart4\Security::admin($this->registry, false, false, false);
        // Financial ledger, profiles, inbox, operations and outbox are deliberately retained.
        $this->load->model('setting/setting');
        $this->model_setting_setting->editValue('payment_lunixi', 'payment_lunixi_status', '0', (int) $this->config->get('config_store_id'));
    }
    public function order()
    {
        $this->load->language('extension/lunixi/payment/lunixi');
        return $this->load->view('extension/lunixi/payment/lunixi_order', ['settings_url' => $this->link('extension/lunixi/payment/lunixi', 'store_id=' . (int) $this->config->get('config_store_id') . '&user_token=' . $this->session->data['user_token'], true), 'text' => $this->language->get('text_order_tools')]);
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
