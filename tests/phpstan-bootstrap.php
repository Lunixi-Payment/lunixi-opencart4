<?php

declare (strict_types=1);
$source = getenv('OPENCART_SOURCE');
if (!is_string($source) || !is_dir($source . '/upload/system/engine')) {
    throw new RuntimeException('Set OPENCART_SOURCE to the pinned official source.');
}
$site = getenv('LUNIXI_TEST_SITE') ?: $source . '/upload';
foreach (['DB_PREFIX' => 'oc_', 'VERSION' => getenv('LUNIXI_PLATFORM_VERSION') ?: '', 'DIR_APPLICATION' => $site . '/catalog/', 'DIR_CATALOG' => $site . '/catalog/', 'DIR_OPENCART' => $site . '/', 'DIR_SYSTEM' => $site . '/system/', 'DIR_STORAGE' => $site . '/system/storage/', 'DIR_IMAGE' => $site . '/image/', 'DIR_EXTENSION' => $site . '/extension/', 'DIR_LANGUAGE' => $site . '/catalog/language/', 'DIR_TEMPLATE' => $site . '/catalog/view/template/', 'DIR_CONFIG' => $site . '/system/config/', 'DIR_CACHE' => sys_get_temp_dir() . '/lunixi-analysis/', 'HTTP_SERVER' => 'https://merchant.example/', 'HTTPS_SERVER' => 'https://merchant.example/', 'HTTP_CATALOG' => 'https://merchant.example/', 'HTTPS_CATALOG' => 'https://merchant.example/'] as $key => $value) {
    if (!defined($key)) {
        define($key, $value);
    }
}
