<?php

declare(strict_types=1);

require_once __DIR__ . '/src/autoload.php';

function plugin_assetsync20agent_install(): bool
{
    return \GlpiPlugin\Assetsync20agent\Outbox::install();
}

function plugin_assetsync20agent_uninstall(): bool
{
    // Watermarks and unacknowledged work survive uninstall. Reinstall requires new credentials.
    \Config::deleteConfigurationValues('plugin:assetsync20agent', ['endpoint', 'registration', 'generation', 'token']);
    return true;
}
