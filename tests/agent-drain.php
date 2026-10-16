<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/agent-task-reporting.php';

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20agent\AgentTransport;
use GlpiPlugin\Assetsync20agent\TaskJournal;

[$credentials, $request, $snapshot] = taskSetup();
[$job, $task] = queueTask();
$snapshot['observed_at'] = time();
$settings = $request + ['computer_id' => 201, 'entity_id' => 100,
    'mode' => 'agent', 'agent_auth' => 'glpi_session', 'agent_api_url' => 'https://controller.invalid/plugins/assetsync20/front/agent-api.php',
    'agent_app_token' => 'app', 'agent_user_token' => 'user'];
$result = ['outcome' => 'unchanged', 'changed' => [], 'snapshot' => $snapshot];
$responses = [['status' => 200, 'body' => []]];
for ($i = 0; $i < 11; $i++) {
    $next = $task;
    $next['id'] = str_pad(dechex($i + 100), 32, '0', STR_PAD_LEFT);
    unset($next['hash']);
    $next['hash'] = AgentTask::hash($next);
    $row = ['id' => $next['id'], 'hash' => $next['hash'], 'body' => json_encode($next), 'state' => 'unchanged',
        'result' => json_encode($result), 'created_at' => time(), 'acknowledged' => 1];
    foreach (['registration', 'generation', 'installation', 'connection_id'] as $key) { $row[$key] = $settings[$key]; }
    $DB->insert(TaskJournal::TABLE, $row);
    $responses[] = ['status' => 200, 'body' => ['task' => $next]];
    $responses[] = ['status' => 200, 'body' => ['recorded' => true, 'id' => $next['id'], 'hash' => $next['hash']]];
}
AgentTransport::$responses = $responses;
$calls = AgentTransport::$protocolCalls;
$journal = iterator_to_array($DB->request(['FROM' => TaskJournal::TABLE]));
agentTestCycle($settings, hrtime(true) + 20_000_000_000);
agentCheck(AgentTransport::$protocolCalls === $calls + 21 && count(AgentTransport::$responses) === 2,
    'A cycle drains exactly ten tasks, acknowledging each before pulling another.');
agentCheck(iterator_to_array($DB->request(['FROM' => TaskJournal::TABLE])) === $journal, 'Drain replay preserves every immutable journal row.');
agentCheck(AgentTransport::$options[CURLOPT_TIMEOUT_MS] <= 5000, 'Task transport uses at most five seconds of the twenty-second worker budget.');

foreach ([
    [['status' => 503, 'body' => []]],
    [['status' => 200, 'body' => []], ['status' => 503, 'body' => []]],
    [['status' => 200, 'body' => []], ['status' => 200, 'body' => ['task' => null]]],
    [['status' => 200, 'body' => []], $responses[1], ['status' => 503, 'body' => []]],
] as $sequence) {
    AgentTransport::$responses = $sequence;
    $calls = AgentTransport::$protocolCalls;
    agentTestCycle($settings, hrtime(true) + 20_000_000_000);
    agentCheck(AgentTransport::$protocolCalls === $calls + count($sequence), 'Transport failure, lost ACK or empty pull ends the drain immediately.');
}
$calls = AgentTransport::$protocolCalls;
AgentTransport::$responses = [];
agentTestCycle($settings, hrtime(true) - 1);
agentCheck(AgentTransport::$protocolCalls === $calls, 'An exhausted cycle budget starts no transport.');

ob_end_clean();
echo "Agent drain tests passed ($checks checks including reporting prerequisites; mocked transport and SQLite).\n";
