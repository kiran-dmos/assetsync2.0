<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\Plugin;
use GlpiPlugin\Assetsync20\Menu;
use GlpiPlugin\Assetsync20\AssetChangeHook;
use GlpiPlugin\Assetsync20\FieldMapping;

/**
 * GLPI bootstrap for the assetsync2.0 plugin.
 */

define('PLUGIN_ASSETSYNC20_VERSION', '0.1.5');
define('PLUGIN_ASSETSYNC20_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_ASSETSYNC20_MAX_GLPI_VERSION', '12.0.0');
define('PLUGIN_ASSETSYNC20_MIN_PHP_VERSION', '8.2.0');

require_once __DIR__ . '/src/autoload.php';

function plugin_init_assetsync20(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant'][Plugin::KEY] = true;
    $PLUGIN_HOOKS['config_page'][Plugin::KEY] = 'front/config.php';
    $PLUGIN_HOOKS['menu_toadd'][Plugin::KEY] = [
        'config' => Menu::class,
    ];

    foreach (array_keys(FieldMapping::assetTypes()) as $itemtype) {
        $PLUGIN_HOOKS['item_add'][Plugin::KEY][$itemtype] = [
            AssetChangeHook::class,
            'onAdd',
        ];
        $PLUGIN_HOOKS['item_update'][Plugin::KEY][$itemtype] = [
            AssetChangeHook::class,
            'onUpdate',
        ];

        foreach (AssetChangeHook::customHookItemtypesFor($itemtype) as $customItemtype) {
            $PLUGIN_HOOKS['item_add'][Plugin::KEY][$customItemtype] = [
                AssetChangeHook::class,
                'onAdd',
            ];
            $PLUGIN_HOOKS['item_update'][Plugin::KEY][$customItemtype] = [
                AssetChangeHook::class,
                'onUpdate',
            ];
        }
    }
}

function plugin_version_assetsync20(): array
{
    return [
        'id'           => Plugin::KEY,
        'name'         => Plugin::NAME,
        'version'      => PLUGIN_ASSETSYNC20_VERSION,
        'author'       => 'DMOS Technologies',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_ASSETSYNC20_MIN_GLPI_VERSION,
                'max' => PLUGIN_ASSETSYNC20_MAX_GLPI_VERSION,
            ],
            'php' => [
                'min' => PLUGIN_ASSETSYNC20_MIN_PHP_VERSION,
            ],
        ],
    ];
}

function plugin_assetsync20_check_prerequisites(): bool
{
    if (version_compare(PHP_VERSION, PLUGIN_ASSETSYNC20_MIN_PHP_VERSION, '<')) {
        echo 'This plugin requires PHP ' . PLUGIN_ASSETSYNC20_MIN_PHP_VERSION . ' or newer.';
        return false;
    }

    if (!defined('GLPI_VERSION')) {
        echo 'This plugin must be loaded from GLPI.';
        return false;
    }

    if (
        version_compare(GLPI_VERSION, PLUGIN_ASSETSYNC20_MIN_GLPI_VERSION, '<')
        || version_compare(GLPI_VERSION, PLUGIN_ASSETSYNC20_MAX_GLPI_VERSION, '>=')
    ) {
        echo 'This plugin requires GLPI >= ' . PLUGIN_ASSETSYNC20_MIN_GLPI_VERSION
            . ' and < ' . PLUGIN_ASSETSYNC20_MAX_GLPI_VERSION . '.';
        return false;
    }

    return true;
}

function plugin_assetsync20_check_config(): bool
{
    return true;
}
