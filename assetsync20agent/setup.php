<?php

declare(strict_types=1);

require_once __DIR__ . '/src/autoload.php';

function plugin_assetsync20agent_boot(): void
{
    \Glpi\Http\SessionManager::registerPluginStatelessPath('assetsync20agent', '#^/front/agent-api\\.php$#D');
    \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('assetsync20agent', '#^/front/agent-api\\.php$#D',
        \Glpi\Http\Firewall::STRATEGY_NO_CHECK);
}

function plugin_init_assetsync20agent(): void
{
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['assetsync20agent'] = true;
    $PLUGIN_HOOKS['config_page']['assetsync20agent'] = 'front/config.php';
    $types = array_keys(array_filter(\GlpiPlugin\Assetsync20agent\TargetScope::types(), static fn (string $reason): bool => $reason === ''));
    foreach (array_merge($types, \GlpiPlugin\Assetsync20agent\Snapshot::hookTypes()) as $type) {
        foreach (['item_add', 'item_update', 'item_delete', 'item_restore', 'item_purge'] as $hook) {
            $PLUGIN_HOOKS[$hook]['assetsync20agent'][$type] = [\GlpiPlugin\Assetsync20agent\ChangeHook::class,
                $hook === 'item_purge' ? 'purge' : 'changed'];
        }
    }
}

function plugin_version_assetsync20agent(): array
{
    return ['id' => 'assetsync20agent', 'name' => 'AssetSync2.0 Agent', 'version' => '0.7.0',
        'author' => 'DMOS Technologies', 'license' => 'GPLv3+', 'requirements' => [
            'glpi' => ['min' => '11.0.0', 'max' => '12.0.0'], 'php' => ['min' => '8.2.0']]];
}

function plugin_assetsync20agent_check_prerequisites(): bool
{
    return defined('GLPI_VERSION') && version_compare(GLPI_VERSION, '11.0.0', '>=')
        && version_compare(GLPI_VERSION, '12.0.0', '<') && extension_loaded('curl');
}

function plugin_assetsync20agent_check_config(): bool
{
    return true;
}
