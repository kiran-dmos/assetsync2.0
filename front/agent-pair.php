<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\AgentTask;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\EntitySyncRoute;

if (!defined('GLPI_ROOT')) { define('GLPI_ROOT', dirname(__DIR__, 3)); }
include GLPI_ROOT . '/inc/includes.php';
require_once __DIR__ . '/../src/autoload.php';
Session::checkCentralAccess();
Session::checkRight('config', UPDATE);
header('Cache-Control: no-store');
$message = '';
$credentials = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $connection = (string) ($_POST['connection'] ?? '');
        $existingPair = AgentTask::pair($connection);
        if ($existingPair && !(new Computer())->can((int) $existingPair['local_id'], READ)) {
            throw new RuntimeException('Agent pair unavailable.');
        }
        switch ($_POST['action'] ?? '') {
            case 'declare':
                $credentials = AgentTask::declare($connection, (string) ($_POST['installation'] ?? ''),
                    (int) ($_POST['local_id'] ?? 0), (int) ($_POST['computer_id'] ?? 0), (int) ($_POST['entity_id'] ?? -1));
                $message = $credentials['inspection_error'] ?? 'Candidate recorded; B inspection queued. Credentials are shown once.';
                unset($credentials['inspection_error']);
                break;
            case 'rotate': $credentials = AgentTask::rotate($connection); $message = 'Token rotated. Update B with the new token shown once below.'; break;
            case 'inspect': AgentTask::inspect($connection); $message = 'Inspection queued.'; break;
            case 'retry':
                $queued = AgentTask::outstanding($connection)['queue_id'] ?? null;
                AgentTask::retryStale($connection);
                $message = $queued !== null ? 'No-write task retained; queued work can resume.' : 'No-write task retained. Use Refresh observation to request another inspection.';
                break;
            case 'confirm': AgentTask::confirm($connection, (string) ($_POST['route'] ?? '')); $message = 'Independent pair confirmed.'; break;
        }
    } catch (Throwable $error) { $message = $error->getMessage(); }
}
$previous = $_SESSION['glpicrontimer'] ?? null;
$_SESSION['glpicrontimer'] = time();
try { Html::header('Agent Pair', $_SERVER['PHP_SELF'], 'config', 'Plugin'); }
finally { if ($previous === null) { unset($_SESSION['glpicrontimer']); } else { $_SESSION['glpicrontimer'] = $previous; } }
$html = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
echo '<h2>Agent Pair</h2><p>' . $html($message) . '</p>';
if ($credentials !== null) {
    echo '<dl>';
    foreach ($credentials as $key => $value) { echo '<dt>' . $html($key) . '</dt><dd><code>' . $html($value) . '</code></dd>'; }
    echo '</dl>';
}
foreach (GlpiBConnection::loadAll() as $connection) {
    if (!GlpiBConnection::isAgent($connection)) { continue; }
    $pair = AgentTask::pair($connection['id']);
    if ($pair && !(new Computer())->can((int) $pair['local_id'], READ)) { continue; }
    echo '<h3>' . $html($connection['name']) . '</h3><p>Connection ID: ' . $html($connection['id']) . '</p><form method="post">'
        . '<input type="hidden" name="connection" value="' . $html($connection['id']) . '">'
        . '<input type="hidden" name="_glpi_csrf_token" value="' . $html(Session::getNewCSRFToken()) . '">';
    if (!$pair) {
        echo '<table class="tab_cadre_fixe">';
        foreach (['installation' => 'B installation identity', 'local_id' => 'Existing A Computer ID', 'computer_id' => 'Existing B Computer ID', 'entity_id' => 'Exact B entity ID'] as $key => $label) {
            echo '<tr><th><label for="' . $html($connection['id'] . $key) . '">' . $label . '</label></th><td><input id="' . $html($connection['id'] . $key) . '" name="' . $key . '" required></td></tr>';
        }
        echo '</table><button class="submit" name="action" value="declare">Declare and inspect</button>';
    } else {
        echo '<p>A #' . (int) $pair['local_id'] . ' / B #' . (int) $pair['computer_id'] . ' / B entity #' . (int) $pair['entity_id']
            . ' / ' . ($pair['confirmed'] ? 'Confirmed' : 'Awaiting independent confirmation') . '</p>';
        echo '<p>Observed: ' . ($pair['observed_at'] ? $html(gmdate('Y-m-d H:i:s', (int) $pair['observed_at'])) . ' UTC' : 'Never') . '</p>';
        echo '<label>Exact Computer route <select name="route"><option value="">Select route</option>';
        foreach (EntitySyncRoute::loadAll() as $route) {
            if ($route['glpi_b_connection_id'] === $connection['id']) { echo '<option value="' . $html($route['id']) . '">' . $html($route['name']) . '</option>'; }
        }
        echo '</select></label> <button class="submit" name="action" value="confirm">Confirm pair</button> '
            . '<button class="submit" name="action" value="inspect">Refresh observation</button> '
            . '<button class="submit" name="action" value="rotate">Rotate token</button>';
        $task = AgentTask::outstanding($connection['id']);
        if ($task) { echo '<p>Task ' . $html($task['id']) . ': ' . $html($task['state']) . '</p><button class="submit" name="action" value="retry">Retry no-write stale task</button>'; }
        foreach (AgentTask::recent($connection['id']) as $recent) {
            echo '<p>' . ($recent['queue_id'] === null ? 'Inspection ' : 'Task ') . $html($recent['id']) . ': ' . $html($recent['state']) . '</p>';
        }
    }
    echo '</form>';
}
Html::footer();
