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
            return match ($row['queue_status']) {'running' => 'processing', 'retry' => 'retrying', default => 'queued'};
        }
        return in_array($row['outcome'], ['received', 'queued', 'unchanged', 'synchronized', 'retrying', 'blocked'], true)
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
        echo '<table class="tab_cadre_fixe"><tr><th>Computer</th><th>B ID</th><th>Received / admitted / completed revision</th><th>Current outcome</th><th>Agent last contact (UTC)</th></tr>';
        foreach ($rows as $row) {
            echo '<tr><td>' . $html($row['asset_name']) . '</td><td>' . (int) $row['computer_id'] . '</td><td>'
                . (int) $row['received_revision'] . ' / ' . (int) $row['admitted_revision'] . ' / ' . (int) $row['completed_revision']
                . '</td><td>' . $html(self::outcome($row)) . '</td><td>'
                . ((int) $row['last_seen'] > 0 ? $html(gmdate('Y-m-d H:i:s', (int) $row['last_seen'])) : 'Never') . '</td></tr>';
        }
        if ($rows === []) {
            echo '<tr><td colspan="5">No agent work for visible Computers.</td></tr>';
        }
        echo '</table>';
    }
}
