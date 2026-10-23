<?php

declare(strict_types=1);

require_once __DIR__ . '/src/autoload.php';

function plugin_init_assetsync20agent(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['assetsync20agent'] = true;
    $PLUGIN_HOOKS['config_page']['assetsync20agent'] = 'front/config.php';

    if (class_exists(\Glpi\Http\SessionManager::class)) {
        \Glpi\Http\SessionManager::registerPluginStatelessPath(
            'assetsync20agent',
            '#^/front/agent-command\\.php$#D'
        );
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'assetsync20agent',
            '#^/front/agent-command\\.php$#D',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
        );
    }

    // GLPI A polls linked GLPI B assets. Agent B does not use callback
    // registrations, an outbox, or local asset-change hooks.
}

function plugin_version_assetsync20agent(): array
{
    return ['id' => 'assetsync20agent', 'name' => 'AssetSync2.0 Agent', 'version' => '0.2.0',
        'author' => 'DMOS Technologies', 'license' => 'GPLv3+', 'requirements' => [
            'glpi' => ['min' => '11.0.0', 'max' => '12.0.0'], 'php' => ['min' => '8.2.0']]];
}

function plugin_assetsync20agent_check_prerequisites(): bool
{
    return extension_loaded('curl');
}
function plugin_assetsync20agent_check_config(): bool
{
    return true;
}
