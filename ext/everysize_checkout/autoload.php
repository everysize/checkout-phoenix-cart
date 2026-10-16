<?php

/**
 * Registers the autoloaders for the everysize checkout add-on: the core
 * (src/, namespace Everysize\Checkout\) and the Phoenix layer (phoenix/,
 * namespace Everysize\Checkout\Phoenix\). The add-on is not composer-
 * installed in the shop, so the admin page, hooks and sync.php pull in
 * this file. Safe to require multiple times. PHP 7.4 compatible.
 */

if (!\function_exists('everysize_checkout_autoload')) {
    function everysize_checkout_autoload(string $class): void
    {
        $map = [
            'Everysize\\Checkout\\Phoenix\\' => __DIR__ . '/phoenix/',
            'Everysize\\Checkout\\' => __DIR__ . '/src/',
        ];
        foreach ($map as $prefix => $dir) {
            if (strpos($class, $prefix) === 0) {
                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require_once $file;

                    return;
                }
            }
        }
    }

    \spl_autoload_register('everysize_checkout_autoload');
}
