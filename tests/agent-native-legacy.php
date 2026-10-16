<?php

declare(strict_types=1);

require __DIR__ . '/agent-tasks.php';

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20agent\Settings;
use GlpiPlugin\Assetsync20agent\Wake;

[$credentials, $request] = taskSetup();
$before = iterator_to_array($DB->request(['FROM' => AgentTask::TABLE]));
foreach ([
    static fn () => AgentTask::exchange($request, str_repeat('d', 64)),
    static fn () => AgentInbox::receive(['registration' => $credentials['registration']], str_repeat('d', 64)),
    static fn () => Wake::receive([], str_repeat('d', 64)),
] as $oldCaller) {
    $denied = false;
    try { $oldCaller(); } catch (TypeError) { $denied = true; }
    agentCheck($denied, 'A custom bearer string cannot enter mandatory native admission.');
}
agentCheck(iterator_to_array($DB->request(['FROM' => AgentTask::TABLE])) === $before,
    'Rejected custom credentials leave all task evidence unchanged.');
foreach (['front/agent-notify.php', 'front/agent-task.php', 'assetsync20agent/front/agent-wake.php'] as $file) {
    agentCheck(!file_exists(dirname(__DIR__) . '/' . $file), 'Obsolete public endpoint removed: ' . $file);
}
agentCheck(!method_exists(AgentTask::class, 'rotate') && !method_exists(AgentInbox::class, 'rotate')
    && !array_key_exists('token', $credentials), 'No custom token issuer or rotation survives.');
echo "Removed bearer admission tests passed ({$checks} checks including task prerequisites; no HTTP).\n";

