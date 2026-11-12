<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader so the pure (Magento-free) classes under this module
 * can be unit tested without a Magento install: Byte8\Pulsar\ maps to the
 * module root, exactly as composer.json declares.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Byte8\\Pulsar\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/../' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
