<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\AgentInbox;
use GlpiPlugin\Assetsync20\SyncActivity;

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}
include GLPI_ROOT . '/inc/includes.php';
require_once __DIR__ . '/../src/autoload.php';
global $DB;
try {
    SyncActivity::checkAccess();
} catch (Throwable) {
    http_response_code(403);
    echo 'Access denied.';
    return;
}
Session::checkRight('config', UPDATE);
header('Cache-Control: no-store');
$registrations = [];
foreach ($DB->request(['FROM' => AgentInbox::REGISTRATIONS]) as $registration) {
    $approved = json_decode($registration['routes'], true);
    if (is_array($approved) && $approved !== [] && array_diff(array_column($approved, 'source_entity'), array_map('intval', Session::getActiveEntities())) === []) {
        $registrations[$registration['id']] = $registration;
    }
}
$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // GLPI 11's controller listener already checked and consumed the form CSRF token.
    try {
        $action = (string) ($_POST['action'] ?? '');
        $id = (string) ($_POST['registration'] ?? '');
        if (!isset($registrations[$id])) {
            throw new RuntimeException('Registration unavailable.');
        }
        if ($action === 'verify_pair') {
            $localId = filter_var($_POST['computer_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($localId === false) { throw new RuntimeException('Computer unavailable.'); }
            AgentInbox::verifySavedPair($registrations[$id], $localId);
            $message = 'Saved Computer pair verified. No asset fields changed.';
        } elseif ($action === 'revoke') {
            if (!$DB->update(AgentInbox::REGISTRATIONS, ['active' => 0], ['id' => $id])) {
                throw new RuntimeException('Revocation failed.');
            }
            $registrations[$id]['active'] = 0;
            $message = 'Registration revoked.';
        } else {
            throw new RuntimeException('Invalid action.');
        }
    } catch (Throwable) {
        $message = 'Registration action failed. Check selected connection, routes and permissions.';
    }
}
SyncActivity::header((string) ($_SERVER['PHP_SELF'] ?? ''));
$html = static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
echo '<h2>Retained Agent Registrations</h2><p>' . $html($message) . '</p>';
foreach ($registrations as $id => $registration) {
    echo '<h3>' . $html($id) . ($registration['active'] ? ' (enabled)' : ' (revoked)') . '</h3><p>Last authenticated contact: '
        . ((int) $registration['last_seen'] > 0 ? $html(gmdate('Y-m-d H:i:s', (int) $registration['last_seen'])) . ' UTC' : 'Never') . '</p>';
    echo '<p>Worker health: ' . $html(\GlpiPlugin\Assetsync20\AgentActivity::health($registration)) . '</p>';
    echo '<form method="post"><input type="hidden" name="_glpi_csrf_token" value="' . $html(Session::getNewCSRFToken()) . '">'
        . '<input type="hidden" name="registration" value="' . $html($id) . '">'
        . '<button name="action" value="revoke">Revoke</button></form>';
    echo '<form method="post"><input type="hidden" name="_glpi_csrf_token" value="' . $html(Session::getNewCSRFToken()) . '">'
        . '<input type="hidden" name="registration" value="' . $html($id) . '"><label>Saved A Computer ID '
        . '<input type="number" min="1" name="computer_id" required></label> <button name="action" value="verify_pair">Verify saved pair</button></form>';
    // Unlinked notifications have no local asset to scope. Only registration-scope administrators see their IDs.
    echo '<table class="tab_cadre_fixe"><tr><th>Unadmitted B Computer</th><th>Revision</th><th>Outcome</th></tr>';
    foreach ($DB->request(['FROM' => AgentInbox::RECEIPTS, 'WHERE' => ['registration_id' => $id, 'queue_id' => null], 'ORDER' => 'received_at DESC', 'LIMIT' => 50]) as $receipt) {
        echo '<tr><td>' . (int) $receipt['computer_id'] . '</td><td>' . (int) $receipt['received_revision'] . '</td><td>Blocked: confirmed pair or approved scope unavailable</td></tr>';
    }
    echo '</table>';
}
Html::footer();
