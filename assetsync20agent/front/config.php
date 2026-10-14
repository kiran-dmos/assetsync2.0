<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20agent\Settings;
use GlpiPlugin\Assetsync20agent\Outbox;
use GlpiPlugin\Assetsync20agent\ExecutionSettings;

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}
include GLPI_ROOT . '/inc/includes.php';
require_once __DIR__ . '/../src/autoload.php';
global $DB;
Session::checkCentralAccess();
Session::checkRight('config', UPDATE);
header('Cache-Control: no-store');
$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // GLPI 11's controller listener already checked and consumed the form CSRF token.
    try {
        if (isset($_POST['save_execution'])) {
            ExecutionSettings::save($_POST);
            $message = 'Authorised-representative settings saved.';
        } else {
            Settings::save($_POST);
            $message = 'Notification-agent settings saved.';
        }
    } catch (Throwable) {
        $message = 'Settings rejected. Check the required values and token.';
    }
}
$settings = Settings::load();
$execution = ExecutionSettings::load();
// Prevent opportunistic GLPI cron work on this page, as on the controller activity page.
$previous = $_SESSION['glpicrontimer'] ?? null;
$_SESSION['glpicrontimer'] = time();
try {
    Html::header('AssetSync2.0 Agent', $_SERVER['PHP_SELF'], 'config', 'Plugin');
} finally {
    if ($previous === null) { unset($_SESSION['glpicrontimer']); } else { $_SESSION['glpicrontimer'] = $previous; }
}
$html = static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
echo '<h2>AssetSync2.0 Agent</h2><p>' . $html($message) . '</p><form method="post"><table class="tab_cadre_fixe">';
foreach (['endpoint' => 'GLPI A HTTPS receiver URL', 'registration' => 'Registration', 'generation' => 'Generation', 'token' => 'Token (blank keeps current)'] as $key => $label) {
    echo '<tr><th><label for="' . $key . '">' . $label . '</label></th><td><input id="' . $key . '" name="' . $key
        . '" type="' . ($key === 'token' ? 'password' : 'text') . '" autocomplete="off" size="72" value="'
        . ($key === 'token' ? '' : $html($settings[$key] ?? '')) . '"></td></tr>';
}
echo '</table><input type="hidden" name="_glpi_csrf_token" value="' . $html(Session::getNewCSRFToken()) . '"><button type="submit" class="submit">Save</button></form>';
echo '<h3>Authorised representative (GLPI A commands)</h3><p>Agent B performs allowed writes through GLPI B\'s internal item-update path. It does not call the GLPI B REST API or write database tables directly.</p>';
echo '<form method="post"><table class="tab_cadre_fixe">';
echo '<tr><th><label for="service_user_id">assetsync-agent-b user ID</label></th><td><input id="service_user_id" name="service_user_id" type="number" min="1" value="' . $html($execution['service_user_id'] ?? '') . '"><br><small>The GLPI B account recorded in History.</small></td></tr>';
foreach (['command_token' => 'Command token shared with GLPI A (blank keeps current)'] as $key => $label) {
    echo '<tr><th><label for="' . $key . '">' . $label . '</label></th><td><input id="' . $key . '" name="' . $key . '" type="password" autocomplete="off" size="72"></td></tr>';
}
echo '</table><input type="hidden" name="_glpi_csrf_token" value="' . $html(Session::getNewCSRFToken()) . '"><button type="submit" name="save_execution" value="1" class="submit">Save authorised-representative settings</button></form>';
echo '<p>Command receiver URL: <code>/plugins/assetsync20agent/front/agent-command.php</code>. First release accepts only Computer Comment updates and returns a verified receipt.</p>';
echo '<p>Last worker cycle: ' . (!empty($settings['worker_seen']) ? $html(gmdate('Y-m-d H:i:s', (int) $settings['worker_seen'])) . ' UTC' : 'Never') . '</p>';
echo '<p>Reconciliation: ' . $html(in_array($settings['reconcile_status'] ?? '', ['ok', 'degraded'], true) ? $settings['reconcile_status'] : 'Not recorded')
    . '. Last health acknowledgement: ' . (!empty($settings['health_ack']) ? $html(gmdate('Y-m-d H:i:s', (int) $settings['health_ack'])) . ' UTC' : 'Never') . '</p>';
echo '<table class="tab_cadre_fixe"><tr><th>Outbox outcome</th><th>Computers</th></tr>';
$result = Outbox::sql('SELECT `last_outcome`, COUNT(*) AS total FROM `' . Outbox::TABLE . '` GROUP BY `last_outcome`');
while ($row = $DB->fetchAssoc($result)) {
    echo '<tr><td>' . $html($row['last_outcome']) . '</td><td>' . (int) $row['total'] . '</td></tr>';
}
echo '</table><p>Accepted notifications remain subject to controller processing and field policies.</p>';
Html::footer();
