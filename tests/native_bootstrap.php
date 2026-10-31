<?php

declare (strict_types=1);
use Opencart\System\Engine\{Registry, Config, Loader, Event, Factory, Autoloader};
use Opencart\System\Library\{DB, Language, Cache, Log, Request, Response, Url, Session, Template, Document};
$site = getenv('LUNIXI_TEST_SITE');
$source = getenv('OPENCART_SOURCE');
$connection = getenv('LUNIXI_TEST_DB');
if (!is_string($site) || !is_file($site . '/config.php') || !is_string($source) || !is_string($connection)) {
    throw new RuntimeException('Native acceptance requires an explicitly installed official disposable fixture.');
}
$db = json_decode(file_get_contents($connection), true, 16, JSON_THROW_ON_ERROR);
if (($db['database'] ?? '') !== 'lunixi_oc4') {
    throw new RuntimeException('Dedicated acceptance schema required.');
}
define('VERSION', '4.1.0.4');
require $site . (getenv('LUNIXI_TEST_ADMIN') === '1' ? '/admin/config.php' : '/config.php');
$_SERVER += ['HTTPS' => 'on', 'HTTP_HOST' => 'merchant.example', 'REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1', 'SERVER_PORT' => 443, 'REQUEST_URI' => '/index.php', 'PHP_SELF' => '/index.php'];
require DIR_SYSTEM . 'startup.php';
$autoloader = new Autoloader();
$autoloader->register('Opencart\Catalog', DIR_OPENCART . 'catalog/');
$autoloader->register('Opencart\System', DIR_SYSTEM);
$autoloader->register('Opencart\Admin', DIR_OPENCART . 'admin/');
$autoloader->register('Opencart\Catalog\Model\Extension\Ocmod', DIR_EXTENSION . 'ocmod/catalog/model/');
$autoloader->register('Opencart\Catalog\Controller\Extension\Ocmod', DIR_EXTENSION . 'ocmod/catalog/controller/');
foreach (['Catalog', 'Admin'] as $context) {
    foreach (['lunixi', 'opencart'] as $extension) {
        $autoloader->register('Opencart\\' . $context . '\Controller\Extension\\' . ucfirst($extension), DIR_EXTENSION . $extension . '/' . strtolower($context) . '/controller/');
        $autoloader->register('Opencart\\' . $context . '\Model\Extension\\' . ucfirst($extension), DIR_EXTENSION . $extension . '/' . strtolower($context) . '/model/');
    }
}
$GLOBALS['lunixiNativeAutoloader'] = $autoloader;
require DIR_STORAGE . 'vendor/autoload.php';
require dirname(__DIR__) . '/tools/vendor/autoload.php';
require DIR_EXTENSION . 'lunixi/system/library/lunixi4/autoload.php';
function nativeRegistry(): Registry
{
    $autoloader = $GLOBALS['lunixiNativeAutoloader'];
    $r = new Registry();
    $r->set('autoloader', $autoloader);
    $config = new Config();
    $config->addPath(DIR_CONFIG);
    $config->load('default');
    $config->load(getenv('LUNIXI_TEST_ADMIN') === '1' ? 'admin' : 'catalog');
    $config->set('application', getenv('LUNIXI_TEST_ADMIN') === '1' ? 'Admin' : 'Catalog');
    $config->set('config_store_id', 0);
    $config->set('config_language', 'en-gb');
    $config->set('config_language_id', 1);
    $r->set('config', $config);
    $db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
    $r->set('db', $db);
    foreach ($db->query('SELECT * FROM `' . DB_PREFIX . 'setting` WHERE store_id=0')->rows as $row) {
        $config->set($row['key'], $row['serialized'] ? json_decode($row['value'], true) : $row['value']);
    }
    $r->set('event', new Event($r));
    foreach ((array) $config->get('action_event') as $trigger => $actions) {
        foreach ($actions as $priority => $action) {
            $r->get('event')->register($trigger, new \Opencart\System\Engine\Action($action), (int) $priority);
        }
    }
    $r->set('factory', new Factory($r));
    $r->set('load', new Loader($r));
    $language = new Language('en-gb');
    $language->addPath(DIR_LANGUAGE);
    $language->addPath('extension/lunixi', DIR_EXTENSION . 'lunixi/' . (getenv('LUNIXI_TEST_ADMIN') === '1' ? 'admin' : 'catalog') . '/language/');
    $language->addPath('extension/opencart', DIR_EXTENSION . 'opencart/' . (getenv('LUNIXI_TEST_ADMIN') === '1' ? 'admin' : 'catalog') . '/language/');
    $language->load('default');
    $language->load('extension/lunixi/payment/lunixi');
    $r->set('language', $language);
    $r->set('cache', new Cache('file', 3600));
    $r->set('log', new Log('lunixi-native-test.log'));
    $r->set('request', new Request());
    $r->set('response', new Response());
    $r->set('url', new Url('https://merchant.example/'));
    $r->set('session', new Session('file', $r));
    $r->get('session')->start();
    $template = new Template('twig');
    $template->addPath(DIR_TEMPLATE);
    $template->addPath('extension/lunixi', DIR_EXTENSION . 'lunixi/' . (getenv('LUNIXI_TEST_ADMIN') === '1' ? 'admin' : 'catalog') . '/view/template/');
    $r->set('template', $template);
    $r->set('document', new Document());
    $r->set('customer', new Opencart\System\Library\Cart\Customer($r));
    $r->set('currency', new Opencart\System\Library\Cart\Currency($r));
    if (getenv('LUNIXI_TEST_ADMIN') === '1') {
        (new Opencart\Admin\Controller\Startup\Event($r))->index();
    } else {
        (new Opencart\Catalog\Controller\Startup\Event($r))->index();
    }
    return $r;
}
