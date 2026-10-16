<?php
declare(strict_types=1);

require __DIR__ . '/agent-http.php';
require __DIR__ . '/agent-tasks.php';

use GlpiPlugin\Assetsync20agent\AgentTransport;
use GlpiPlugin\Assetsync20agent\TaskJournal;

function agentTestCycle(array $settings, int $deadline): void
{
    \GlpiPlugin\Assetsync20agent\AgentSession::begin($deadline);
    try { TaskJournal::cycle($settings, $deadline); }
    finally { \GlpiPlugin\Assetsync20agent\AgentSession::end(); }
}

[$credentials, $request, $snapshot] = taskSetup();
[$job, $task] = queueTask();
$snapshot['observed_at'] = time();
$settings = $request + ['computer_id' => 201, 'entity_id' => 100,
    'mode' => 'agent', 'agent_auth' => 'glpi_session', 'agent_api_url' => 'https://controller.invalid/plugins/assetsync20/front/agent-api.php',
    'agent_app_token' => 'app', 'agent_user_token' => 'user'];
$result = ['outcome' => 'unchanged', 'changed' => [], 'snapshot' => $snapshot];
$row = ['id' => $task['id'], 'hash' => $task['hash'], 'body' => json_encode($task),
    'state' => 'unchanged', 'result' => json_encode($result), 'created_at' => time()];
foreach (['registration', 'generation', 'installation', 'connection_id'] as $key) { $row[$key] = $settings[$key]; }
for ($i = 0; $i < 21; $i++) {
    $old = $row;
    $old['id'] = str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
    $old['generation'] = 'previous-generation';
    $old['created_at'] = time() - 100;
    $DB->insert(TaskJournal::TABLE, $old);
}
$DB->insert(TaskJournal::TABLE, $row);
AgentTransport::$status = 409;
AgentTransport::$body = '{}';
AgentTransport::$responses = [
    ['status' => 200, 'body' => []], ['status' => 409, 'body' => []], ['status' => 200, 'body' => ['task' => null]],
];
$calls = AgentTransport::$protocolCalls;
agentTestCycle($settings, hrtime(true) + 1_000_000_000);
$saved = $DB->fetchAssoc($DB->doQuery('SELECT * FROM `' . TaskJournal::TABLE . '` WHERE id=' . $DB->quote($task['id'])));
agentCheck($saved['report_status'] === 'rejected' && (int) $saved['acknowledged'] === 0 && $saved['result'] === json_encode($result),
    'Current binding is selected before LIMIT despite 21 old binding rows; rejection retains evidence without claiming ACK.');
agentCheck(AgentTransport::$protocolCalls === $calls + 3 && AgentTransport::$payloads[count(AgentTransport::$payloads) - 2]['kind'] === 'pull',
    'Terminal report rejection still permits the next pull.');
$calls = AgentTransport::$protocolCalls;
TaskJournal::cycle($settings, hrtime(true) + 1_000_000_000);
agentCheck(AgentTransport::$protocolCalls === $calls + 2, 'Rejected old report cannot permanently pin subscription updates or later task pulls.');

$nativeSettings = array_replace($settings, ['agent_auth' => 'glpi_session', 'endpoint' => '', 'token' => '',
    'agent_api_url' => 'https://a.invalid/plugins/assetsync20/front/agent-api.php',
    'agent_app_token' => 'native-app', 'agent_user_token' => 'native-user']);
foreach ([401, 400] as $authStatus) {
$DB->update(TaskJournal::TABLE, ['report_status' => ''], ['id' => $task['id']]);
AgentTransport::$responses = [
    ['status' => 200, 'body' => ['session_token' => 'native-session']],
    ['status' => 200, 'body' => []],
    ['status' => $authStatus, 'body' => $authStatus === 400
        ? ['ERROR_SESSION_TOKEN_MISSING', 'Session expired'] : ['error' => 'Session expired']],
    ['status' => 200, 'body' => []],
];
\GlpiPlugin\Assetsync20agent\AgentSession::begin(hrtime(true) + 5_000_000_000);
try { TaskJournal::cycle($nativeSettings, hrtime(true) + 4_000_000_000); }
finally { \GlpiPlugin\Assetsync20agent\AgentSession::end(); }
$saved = $DB->fetchAssoc($DB->doQuery('SELECT * FROM ' . TaskJournal::TABLE . ' WHERE id=' . $DB->quote($task['id'])));
agentCheck($saved['report_status'] === '' && (int) $saved['acknowledged'] === 0 && $saved['result'] === json_encode($result),
    'Native session expiry preserves the durable result for a later turn without protocol rejection or acknowledgement.');
}

echo "Agent task reporting tests passed ($checks total checks including prerequisites).\n";
