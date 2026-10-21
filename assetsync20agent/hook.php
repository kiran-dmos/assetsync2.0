<?php

declare(strict_types=1);

require_once __DIR__ . '/src/autoload.php';

function plugin_assetsync20agent_install(): bool
{
    \GlpiPlugin\Assetsync20agent\Settings::install();
    return \GlpiPlugin\Assetsync20agent\Outbox::install() && \GlpiPlugin\Assetsync20agent\TaskJournal::install()
        && \GlpiPlugin\Assetsync20agent\TargetScope::install()
        && \GlpiPlugin\Assetsync20agent\RestSettings::install() && \GlpiPlugin\Assetsync20agent\RestWork::install()
        && \GlpiPlugin\Assetsync20agent\FieldEvidence::install();
}

function plugin_assetsync20agent_uninstall(): bool
{
    \GlpiPlugin\Assetsync20agent\FieldEvidence::deactivate();
    // Watermarks and unacknowledged work survive uninstall. Reinstall requires new credentials.
    \Config::deleteConfigurationValues('plugin:assetsync20agent', ['endpoint', 'registration', 'generation', 'token', 'service_password',
        'wake_enabled', 'wake_token_hash', 'wake_binding', 'agent_auth', 'agent_api_url', 'agent_app_token', 'agent_user_token',
        'agent_peer_user', 'agent_peer_api_client', 'agent_peer_profile', 'agent_peer_entity']);
    return true;
}
