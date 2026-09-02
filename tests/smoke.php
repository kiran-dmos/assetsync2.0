<?php

declare(strict_types=1);

define('GLPI_VERSION', '11.0.7');

require_once dirname(__DIR__) . '/setup.php';
require_once dirname(__DIR__) . '/hook.php';

$metadata = plugin_version_assetsync20();

$expectations = [
    'id' => 'assetsync20',
    'name' => 'AssetSync2.0',
    'version' => '0.1.1',
];

foreach ($expectations as $key => $expected) {
    if (($metadata[$key] ?? null) !== $expected) {
        throw new RuntimeException(sprintf(
            'Expected metadata %s to be %s, got %s.',
            $key,
            $expected,
            var_export($metadata[$key] ?? null, true)
        ));
    }
}

if (!plugin_assetsync20_check_prerequisites()) {
    throw new RuntimeException('Prerequisite check failed.');
}

if (!plugin_assetsync20_check_config()) {
    throw new RuntimeException('Configuration check failed.');
}

if (!plugin_assetsync20_install()) {
    throw new RuntimeException('Install hook failed.');
}

if (!plugin_assetsync20_uninstall()) {
    throw new RuntimeException('Uninstall hook failed.');
}

$menu = \GlpiPlugin\Assetsync20\Menu::getMenuContent();

if (($menu['title'] ?? null) !== 'AssetSync2.0') {
    throw new RuntimeException('Menu title is incorrect.');
}

if (($menu['icon'] ?? null) !== 'ti ti-refresh') {
    throw new RuntimeException('Menu icon is incorrect.');
}

echo "assetsync2.0 smoke test passed.\n";
