<?php

declare(strict_types=1);

require_once __DIR__ . '/src/autoload.php';

function plugin_assetsync20agent_install(): bool
{
    // No callback outbox is needed. GLPI A polls linked GLPI B assets.
    return true;
}

function plugin_assetsync20agent_uninstall(): bool
{
    \GlpiPlugin\Assetsync20agent\ExecutionSettings::clear();
    return true;
}