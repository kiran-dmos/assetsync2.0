<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class AgentActivity
{
    public static function health(array $registration): string
    {
        if (empty($registration['active'])) { return 'Revoked'; }
        if (empty($registration['last_heartbeat'])) { return 'No heartbeat received'; }
        if (time() - (int) $registration['last_heartbeat'] > 180) { return 'Heartbeat stale'; }
        return ($registration['reconcile_status'] ?? '') === 'ok' ? 'Worker reporting; reconciliation OK' : 'Degraded reconciliation';
    }

    public static function outcome(array $row): string
    {
        if ($row['outcome'] === 'blocked' || (int) $row['received_revision'] > (int) $row['admitted_revision']) {
            return 'blocked';
        }
        if ((int) $row['work_generation'] > (int) $row['agent_completed']) {
            return match ($row['queue_status']) {'running' => 'processing', 'retry' => 'retrying', 'waiting_agent' => 'waiting_agent', 'pending', 'done' => 'queued', 'blocked' => 'blocked', default => 'unknown'};
        }
        return in_array($row['outcome'], ['received', 'queued', 'unchanged', 'synchronized', 'retrying', 'blocked', 'verified', 'partial', 'uncertain', 'stale'], true)
            ? $row['outcome'] : 'received';
    }

    public static function render(): void
    {
        try {
            $activity = new SyncActivity($GLOBALS['DB']);
            $rows = $activity->agentRows();
        } catch (\Throwable) {
            return;
        }
        $html = static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<h3>B Agent Notifications</h3><p>Accepted means durably queued. Only synchronized records verified field updates.</p>';
        echo '<p><a href="' . $html(dirname(Menu::configUrl()) . '/agents.php') . '">Agent registrations and blocked notifications</a></p>';
        $entities = array_map('intval', \Session::getActiveEntities());
        echo '<table class="tab_cadre_fixe"><tr><th>Registration</th><th>Worker health</th></tr>';
        foreach ($GLOBALS['DB']->request(['FROM' => AgentInbox::REGISTRATIONS, 'ORDER' => 'id ASC', 'LIMIT' => 100]) as $registration) {
            $approved = json_decode($registration['routes'], true);
            if (is_array($approved) && $approved !== [] && array_diff(array_column($approved, 'source_entity'), $entities) === []) {
                echo '<tr><td>' . $html($registration['id']) . '</td><td>' . $html(self::health($registration)) . '</td></tr>';
            }
        }
        echo '</table>';
        echo '<table class="tab_cadre_fixe"><tr><th>Asset</th><th>B ID</th><th>Received / admitted / completed revision</th><th>Current outcome</th><th>Agent last contact (UTC)</th></tr>';
        foreach ($rows as $row) {
            echo '<tr><td>' . $html(($row['itemtype'] ?? 'Computer') . ': ' . $row['asset_name']) . '</td><td>' . (int) $row['computer_id'] . '</td><td>'
                . (int) $row['received_revision'] . ' / ' . (int) $row['admitted_revision'] . ' / ' . (int) $row['completed_revision']
                . '</td><td>' . $html(self::outcome($row)) . '</td><td>'
                . ((int) $row['last_seen'] > 0 ? $html(gmdate('Y-m-d H:i:s', (int) $row['last_seen'])) : 'Never') . '</td></tr>';
        }
        if ($rows === []) {
            echo '<tr><td colspan="5">No agent work for visible assets.</td></tr>';
        }
        echo '</table>';
        self::renderTasks($entities, $html);
    }

    public static function renderTasks(array $entities, callable $html, ?string $connection = null): void
    {
        global $DB;
        if (!$DB->tableExists(AgentTask::TABLE)) { return; }
        echo '<h3>Agent Tasks</h3><table class="tab_cadre_fixe"><tr><th>Task</th><th>Asset</th><th>Outcome</th><th>Created (UTC)</th></tr>';
        foreach ($DB->request(['FROM' => AgentTask::TABLE, 'WHERE' => $connection === null ? [] : ['connection_id' => $connection], 'ORDER' => 'created_at DESC', 'LIMIT' => 100]) as $task) {
            $pair = AgentTask::taskPair($task);
            $body = json_decode($task['body'], true);
            if (AgentTask::isMatch($body)) {
                $context = json_decode($task['context'], true);
                $pair = ['itemtype' => $body['itemtype'], 'local_id' => $context['local_id'], 'local_entity' => $context['scope']['local_entity']];
            }
            if (!$pair) { continue; }
            $item = AgentTask::model($pair['itemtype']);
            if (!$pair || !in_array((int) $pair['local_entity'], $entities, true) || !$item->can((int) $pair['local_id'], READ)) { continue; }
            $state = $task['state'] === 'finalizing' ? 'executing' : $task['state'];
            $label = in_array($state, AgentTask::OUTCOMES, true) ? $state : 'unknown';
            if (AgentTask::isMatch($body)) {
                $label = match ($state) {
                    'accepted' => 'Matching queued', 'executing' => 'Reading B identities', 'matched' => 'Matched; synchronization pending',
                    'verified', 'unchanged' => 'Identity evidence received', default => 'Matching ' . $label,
                };
                $queue = AgentInbox::one('SELECT * FROM `' . AssetSyncQueue::TABLE . '` WHERE `id` = ' . (int) $task['queue_id']);
                if ($queue && !$task['slot'] && $state === 'blocked') { $label .= ': ' . $queue['last_error']; }
                if ($body['version'] === 5) {
                    $result = json_decode($task['result'], true);
                    $createdId = $result['created_id'] ?? $context['created']['items_id'] ?? null;
                    $label = $state === 'matched' ? 'Created and confirmed; synchronization pending'
                        : ($createdId ? 'Created B #' . $createdId . '; awaiting confirmation'
                            : 'Creation ' . $state);
                    if (isset($result['attempted_id'])) { $label = 'Uncertain creation; B model returned #' . (int) $result['attempted_id']; }
                    if ($queue && $task['slot'] && in_array($state, ['blocked', 'stale', 'uncertain'], true)) {
                        $label .= ': ' . $queue['last_error'];
                    }
                }
            }
            echo '<tr><td>' . $html($task['id']) . '</td><td>' . $html($pair['itemtype']) . ' #' . (int) $pair['local_id'] . '</td><td>'
                . $html($label) . '</td><td>'
                . $html(gmdate('Y-m-d H:i:s', (int) $task['created_at'])) . '</td></tr>';
        }
        echo '</table>';
        echo '<h4>Unpaired agent assets</h4><table class="tab_cadre_fixe"><tr><th>Asset</th><th>Matching outcome</th></tr>';
        foreach ($DB->request(['FROM' => AssetSyncQueue::TABLE, 'WHERE' => $connection === null ? [] : ['glpi_b_connection_id' => $connection],
            'ORDER' => 'id DESC', 'LIMIT' => 100]) as $queue) {
            $connectionRow = AgentInbox::connection($queue['glpi_b_connection_id']);
            if (!$connectionRow || !GlpiBConnection::isAgent($connectionRow)
                || !in_array($queue['itemtype'], ['Computer', 'Printer'], true)
                || AgentTask::pair($queue['glpi_b_connection_id'], $queue['itemtype'], (int) $queue['items_id'])) { continue; }
            $item = AgentTask::model($queue['itemtype']);
            if (!$item->getFromDB((int) $queue['items_id']) || !in_array((int) $item->fields['entities_id'], $entities, true)
                || !$item->can((int) $queue['items_id'], READ)) { continue; }
            echo '<tr><td>' . $html($queue['itemtype']) . ' #' . (int) $queue['items_id'] . '</td><td>'
                . $html($queue['last_error'] ?: 'Matching ' . $queue['status']) . '</td></tr>';
        }
        echo '</table>';
    }
}
