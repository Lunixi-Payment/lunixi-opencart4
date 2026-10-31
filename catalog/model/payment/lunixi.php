<?php

declare (strict_types=1);
namespace Opencart\Catalog\Model\Extension\Lunixi\Payment;

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
class Lunixi extends \Opencart\System\Engine\Model
{
    public function getMethods(array $address = []): array
    {
        $this->load->language('extension/lunixi/payment/lunixi');
        if (!$this->config->get('payment_lunixi_status') || !(int) $this->config->get('payment_lunixi_profile_id') || $this->cart->hasSubscription()) {
            return [];
        }
        try {
            require_once DIR_EXTENSION . 'lunixi/system/library/lunixi4/autoload.php';
            $kernel = new \Lunixi\OpenCart4\Kernel($this->registry);
            $kernel->compatibility()->assertReady();
            $kernel->assertConfigured();
            \Lunixi\OpenCart4\Kernel::secretBox();
            return ['code' => 'lunixi', 'name' => $this->language->get('text_title'), 'option' => ['lunixi' => ['code' => 'lunixi.lunixi', 'name' => $this->language->get('text_title')]], 'sort_order' => (int) $this->config->get('payment_lunixi_sort_order')];
        } catch (\Throwable $error) {
            return [];
        }
    }
}
