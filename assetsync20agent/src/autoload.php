<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Assetsync20agent\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
