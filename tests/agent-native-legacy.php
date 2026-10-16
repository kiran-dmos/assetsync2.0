<?php

declare(strict_types=1);

require __DIR__ . '/agent-tasks.php';

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20agent\Settings;
use GlpiPlugin\Assetsync20agent\Wake;

[$credentials, $request] = taskSetup();
$values = Config::$values;
$connections = json_decode(Config::$values[GlpiBConnection::CONTEXT]['glpib_connections'], true);
$identity = AgentInbox::identity(GlpiBConnection::find('b'));
$connections[0]['agent_auth'] = 'glpi_session';
Config::$values[GlpiBConnection::CONTEXT]['glpib_connections'] = json_encode($connections);
GlpiBConnection::endRunCache();
try {
    $denied = false;
    try { AgentTask::exchange($request, $credentials['token']); }
    catch (UnexpectedValueException) { $denied = true; }
    agentCheck($denied, 'Old task receiver rejects valid legacy token when the connection selects native sessions.');
    $event = ['registration' => $credentials['registration'], 'generation' => $credentials['generation'],
        'computer_id' => 201, 'revision' => 1, 'lifecycle' => 'present'];
    agentCheck(AgentInbox::receive($event, $credentials['token'])['status'] === 401,
        'Old notification/health receiver cannot authenticate native-mode bindings with retained bearer secret.');
    agentCheck(AgentInbox::identity(GlpiBConnection::find('b')) === $identity, 'Transport selection never changes asset link identity.');
} finally { Config::$values = $values; GlpiBConnection::endRunCache(); }
$settings = ['mode' => 'agent', 'installation' => str_repeat('a', 32), 'registration' => $credentials['registration'],
    'generation' => $credentials['generation'], 'connection_id' => 'b', 'wake_enabled' => '1',
    'endpoint' => 'https://a.invalid/plugins/assetsync20/front/agent-notify.php', 'token' => $credentials['token'],
    'service_user' => '7', 'service_profile' => '4', 'service_name' => 'fixture', 'service_password' => 'password',
    'wake_token_hash' => hash('sha256', str_repeat('d', 64))];
$settings['wake_binding'] = Settings::wakeBinding($settings);
$wake = array_intersect_key($settings, array_flip(['installation', 'registration', 'generation', 'connection_id']))
    + ['kind' => 'wake', 'version' => 1];
Wake::authorize($wake, str_repeat('d', 64), $settings);
$settings['agent_auth'] = 'glpi_session';
$denied = false;
try { Wake::authorize($wake, str_repeat('d', 64), $settings); }
catch (UnexpectedValueException) { $denied = true; }
agentCheck($denied, 'Old wake receiver rejects retained bearer token in native mode.');
echo "Native/legacy separation tests passed ({$checks} checks including task prerequisites; no HTTP).\n";

