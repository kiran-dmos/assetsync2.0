<?php

declare(strict_types=1);

/**
 * Lightweight PSR-4 autoloader for early GLPI bootstrap.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Assetsync20\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
