<?php

declare(strict_types=1);

require __DIR__ . '/agent-tasks.php';

use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AgentSession;
use GlpiPlugin\Assetsync20\AssetSyncLink;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20agent\Outbox;
use GlpiPlugin\Assetsync20agent\Settings;
use GlpiPlugin\Assetsync20agent\TaskJournal;

[$identifiers, $request] = taskSetup();
[$job, $task] = queueTask();
$DB->insert(TaskJournal::TABLE, ['id' => str_repeat('e', 32), 'hash' => str_repeat('f', 64),
    'body' => '{"version":1,"retained":true}', 'result' => '{"outcome":"uncertain","changed":[]}',
    'state' => 'uncertain', 'created_at' => 123, 'registration' => $request['registration'],
    'generation' => $request['generation'], 'installation' => $request['installation'], 'connection_id' => 'b']);
function retainedEvidence(): array
{
    global $DB;
    $rows = [];
    foreach ([AgentTask::TABLE, AgentTask::PAIRS, AgentTask::BINDINGS, AgentInbox::REGISTRATIONS,
        AgentInbox::RECEIPTS, AssetSyncLink::TABLE, Outbox::TABLE, TaskJournal::TABLE] as $table) {
        $rows[$table] = iterator_to_array($DB->request(['FROM' => $table]));
    }
    return $rows;
}
$before = retainedEvidence();
$entries = json_decode(Config::$values[GlpiBConnection::CONTEXT]['glpib_connections'], true);
$entries[0]['agent_auth'] = 'glpi_session';
$entries[0]['wake_token'] = 'obsolete-custom-secret';
$entries[0]['wake_enabled'] = true;
$entries[] = ['id' => 'ordinary', 'mode' => 'rest', 'active' => true, 'base_url' => 'https://ordinary.invalid',
    'app_token' => 'unchanged-native-app', 'user_token' => 'unchanged-native-user'];
$ordinary = $entries[1];
Config::$values[GlpiBConnection::CONTEXT]['glpib_connections'] = json_encode($entries);
Config::$values[Settings::CONTEXT] = ['mode' => 'rest', 'installation' => $request['installation'],
    'registration' => $request['registration'], 'generation' => $request['generation'], 'connection_id' => 'b',
    'token' => 'obsolete-custom-secret', 'endpoint' => 'https://retired.invalid/front/agent-notify.php',
    'wake_token_hash' => str_repeat('a', 64), 'wake_enabled' => '1', 'wake_binding' => 'obsolete'];
GlpiBConnection::upgradeNativeOnly();
Settings::install();
$once = Config::$values;
GlpiBConnection::upgradeNativeOnly();
Settings::install();
agentCheck(Config::$values === $once, 'Native-only configuration upgrade is idempotent.');
agentCheck(retainedEvidence() === $before && hash('sha256', serialize(retainedEvidence())) === hash('sha256', serialize($before)),
    'Upgrade conserves exact task bodies/hashes/results, journal evidence, registrations, links, claims and pairs.');
$after = json_decode(Config::$values[GlpiBConnection::CONTEXT]['glpib_connections'], true);
agentCheck($after[1] === $ordinary, 'Ordinary REST configuration and native GLPI credentials are unchanged.');
foreach (['installation', 'registration', 'generation', 'connection_id'] as $key) {
    agentCheck(Settings::load()[$key] === $request[$key], 'B binding identifier survives: ' . $key);
}
agentCheck(($DB->firstRow(AgentInbox::REGISTRATIONS, ['id' => $request['registration']])['token_hash'] ?? null) === '',
    'New registration keeps the inert schema column empty without issuing a secret.');
agentCheck((new \GlpiPlugin\Assetsync20agent\Worker())->run() === 0
    && retainedEvidence() === $before, 'Retired B configuration is inert even with retained tasks and custom credentials.');
echo "Native-only upgrade tests passed ({$checks} checks including task prerequisites; exact SQLite evidence conservation).\n";
