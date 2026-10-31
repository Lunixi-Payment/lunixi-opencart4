<?php

declare (strict_types=1);
namespace Lunixi\OpenCart4;

/** A filtered native dispatcher: transactional business hooks run; audited mail hooks go to the outbox. */
final class DeferredHistoryEvents extends \Opencart\System\Engine\Event
{
    private object $delegate;
    public function __construct(\Opencart\System\Engine\Registry $registry, object $delegate)
    {
        parent::__construct($registry);
        $this->delegate = $delegate;
        $property = new \ReflectionProperty($delegate, 'data');
        foreach ($property->getValue($delegate) as $registration) {
            if (!self::deferred($registration['trigger'], $registration['action']->getId())) {
                $this->data[] = $registration;
            }
        }
    }
    public static function deferred(string $trigger, string $route): bool
    {
        return in_array($trigger, ['model/checkout/order.addHistory/before', 'model/checkout/order.addHistory/after'], true) && in_array($route, ['mail/order', 'mail/order.alert'], true);
    }
    public function register(string $trigger, \Opencart\System\Engine\Action $action, int $priority = 0): void
    {
        $this->delegate->register($trigger, $action, $priority);
        if (!self::deferred($trigger, $action->getId())) {
            parent::register($trigger, $action, $priority);
        }
    }
    public function unregister(string $trigger, string $route): void
    {
        $this->delegate->unregister($trigger, $route);
        parent::unregister($trigger, $route);
    }
    public function clear(string $trigger): void
    {
        $this->delegate->clear($trigger);
        parent::clear($trigger);
    }
}
