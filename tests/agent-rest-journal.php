<?php

declare(strict_types=1);

ob_start();
require __DIR__ . '/agent-task-reporting.php';
ob_end_clean();

use GlpiPlugin\Assetsync20agent\AgentTransport;
use GlpiPlugin\Assetsync20agent\TaskJournal;

$rest = $row;
$rest['id'] = md5('rest-journal-retained');
$rest['body'] = json_encode(['version' => 7, 'id' => $rest['id']]);
$rest['hash'] = hash('sha256', $rest['body']);
$rest['report_status'] = 'rest';
$rest['acknowledged'] = 0;
$DB->insert(TaskJournal::TABLE, $rest);
$before = $DB->fetchAssoc($DB->doQuery('SELECT * FROM `' . TaskJournal::TABLE . '` WHERE `id` = ' . $DB->quote($rest['id'])));
AgentTransport::$responses = [['status' => 200, 'body' => []], ['status' => 200, 'body' => ['task' => null]]];
$offset = count(AgentTransport::$payloads);
agentTestCycle($settings, hrtime(true) + 1_000_000_000);
foreach (array_slice(AgentTransport::$payloads, $offset) as $payload) {
    if (($payload['id'] ?? '') === $rest['id']) { throw new RuntimeException('REST journal leaked to legacy result transport'); }
}
$after = $DB->fetchAssoc($DB->doQuery('SELECT * FROM `' . TaskJournal::TABLE . '` WHERE `id` = ' . $DB->quote($rest['id'])));
if ($before !== $after || (int) $after['acknowledged'] !== 0) { throw new RuntimeException('Legacy cycle changed REST journal or faked ACK'); }
echo "REST journal coexistence passed: explicit rest channel stays unacknowledged and out of legacy reporting.\n";
