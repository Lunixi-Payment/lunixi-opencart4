<?php

declare (strict_types=1);
namespace Lunixi\OpenCart4;

/** Native per-store configuration selection; credentials never inherit across stores. */
final class StoreContext
{
    public static function select(\Opencart\System\Engine\Registry $registry, int $storeId): void
    {
        $db = $registry->get('db');
        if ($storeId < 0 || $storeId > 0 && !$db->query('SELECT store_id FROM `' . DB_PREFIX . 'store` WHERE store_id=' . $storeId)->num_rows) {
            throw new \RuntimeException('Unknown native store.');
        }
        $config = clone $registry->get('config');
        foreach ($db->query('SELECT * FROM `' . DB_PREFIX . 'setting` WHERE store_id=0 ORDER BY setting_id')->rows as $row) {
            $config->set($row['key'], $row['serialized'] ? json_decode($row['value'], true) : $row['value']);
        }
        if ($storeId > 0) {
            $config->set('payment_lunixi_status', 0);
            $config->set('payment_lunixi_profile_id', 0);
            foreach ($db->query('SELECT * FROM `' . DB_PREFIX . 'setting` WHERE store_id=' . $storeId . ' ORDER BY setting_id')->rows as $row) {
                $config->set($row['key'], $row['serialized'] ? json_decode($row['value'], true) : $row['value']);
            }
        }
        $config->set('config_store_id', $storeId);
        $registry->set('config', $config);
    }
    public static function url(\Opencart\System\Engine\Registry $registry): string
    {
        $id = (int) $registry->get('config')->get('config_store_id');
        if ($id > 0) {
            $row = $registry->get('db')->query('SELECT url FROM `' . DB_PREFIX . 'store` WHERE store_id=' . $id)->row;
            $url = $row['url'];
        } else {
            $url = $registry->get('config')->get('config_url') ?: (defined('HTTP_CATALOG') ? HTTP_CATALOG : (defined('HTTP_SERVER') ? HTTP_SERVER : ''));
        }
        $u = is_string($url) ? parse_url($url) : false;
        if (!$u || ($u['scheme'] ?? '') !== 'https' || !isset($u['host']) || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment'])) {
            throw new \RuntimeException('Configure a native HTTPS store URL.');
        }
        return rtrim($url, '/') . '/';
    }
}
