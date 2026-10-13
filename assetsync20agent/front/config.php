<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20agent\Settings;
use GlpiPlugin\Assetsync20agent\Outbox;

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}
include GLPI_ROOT . '/inc/includes.php';
require_once __DIR__ . '/../src/autoload.php';
global $DB;
Session::checkCentralAccess();
Session::checkRight('config', READ);
$canConfigure = Session::haveRight('config', UPDATE);
header('Cache-Control: no-store');
$message = '';
$newWakeToken = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Session::checkRight('config', UPDATE);
    // GLPI 11's controller listener already checked and consumed the form CSRF token.
    try {
        if (isset($_POST['save_settings'])) {
            Settings::save($_POST);
            $message = 'Agent settings saved.';
        } elseif (isset($_POST['save_wake']) || isset($_POST['rotate_wake']) || isset($_POST['revoke_wake'])) {
            $enabled = !isset($_POST['revoke_wake']) && (isset($_POST['rotate_wake']) || !empty($_POST['wake_enabled']));
            $newWakeToken = Settings::configureWake($enabled, isset($_POST['rotate_wake']));
            $message = $enabled ? 'HTTPS wake enabled.' : 'HTTPS wake disabled and key revoked.';
        }
    } catch (Throwable $error) {
        $message = $error->getMessage();
    }
}
$settings = Settings::load();
$display = array_replace($settings, array_intersect_key($_POST, array_flip(['mode', 'endpoint', 'registration', 'generation', 'connection_id'])));
// Prevent opportunistic GLPI cron work on this page, as on the controller activity page.
$previous = $_SESSION['glpicrontimer'] ?? null;
$_SESSION['glpicrontimer'] = time();
try {
    Html::header('AssetSync2.0 Agent', $_SERVER['PHP_SELF'], 'config', 'Plugin');
} finally {
    if ($previous === null) { unset($_SESSION['glpicrontimer']); } else { $_SESSION['glpicrontimer'] = $previous; }
}
$html = static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
echo '<style>.assetsync-agent-setup{max-width:980px;margin:1rem auto 2rem;padding:0 1rem;box-sizing:border-box}
.assetsync-agent-setup table{width:100%;table-layout:fixed}.assetsync-agent-setup th{width:30%}
.assetsync-agent-setup td{overflow-wrap:anywhere}.assetsync-agent-setup input,.assetsync-agent-setup select,
.assetsync-agent-setup .select2-container{max-width:100%;box-sizing:border-box}
.assetsync-agent-setup .btn{margin:.25rem 0;white-space:normal}</style>';
echo '<div class="assetsync-agent-setup"><h2>AssetSync2.0 Agent</h2><p>' . $html($message) . '</p><fieldset style="border:0;padding:0;min-width:0"' . ($canConfigure ? '' : ' disabled') . '><form method="post"><table class="tab_cadre_fixe">';
echo '<tr><th>Installation identity</th><td>' . $html($settings['installation'] ?? 'Install the plugin update first') . '</td></tr>';
echo '<tr><th><label for="mode">Mode</label></th><td><select id="mode" name="mode">';
foreach (['rest' => 'Notifications for REST', 'agent' => 'Bounded agent tasks'] as $value => $label) {
    echo '<option value="' . $value . '"' . (($display['mode'] ?? 'rest') === $value ? ' selected' : '') . '>' . $label . '</option>';
}
echo '</select></td></tr>';
foreach (['endpoint' => 'GLPI A HTTPS receiver URL', 'registration' => 'Registration', 'generation' => 'Generation'] as $key => $label) {
    echo '<tr><th><label for="' . $key . '">' . $label . '</label></th><td><input id="' . $key . '" name="' . $key
        . '" type="' . ($key === 'token' ? 'password' : 'text') . '" autocomplete="off" size="72" value="'
        . ($key === 'token' ? '' : $html($display[$key] ?? '')) . '"></td></tr>';
}
foreach (['connection_id' => 'A connection binding'] as $key => $label) {
    echo '<tr><th><label for="' . $key . '">' . $label . '</label></th><td><input id="' . $key . '" name="' . $key
        . '" type="' . ($key === 'service_password' ? 'password' : 'text') . '" autocomplete="new-password" size="60" value="'
        . ($key === 'service_password' ? '' : $html($display[$key] ?? '')) . '"></td></tr>';
}
$serviceUser = (int) ($_POST['service_user'] ?? $settings['service_user'] ?? 0);
$serviceProfile = (int) ($_POST['service_profile'] ?? $settings['service_profile'] ?? 0);
echo '<tr><th>Service account</th><td>';
User::dropdown(['name' => 'service_user', 'value' => $serviceUser, 'right' => 'all', 'entity' => -1,
    'condition' => ['is_active' => 1, 'is_deleted' => 0]]);
echo ' <button name="load_options" value="1" class="btn btn-secondary btn-sm">Load account permissions</button></td></tr>';
echo '<tr><th>Permitted profile</th><td>';
Dropdown::show('Profile', ['name' => 'service_profile', 'value' => $serviceProfile,
    'condition' => ['id' => new \Glpi\DBAL\QuerySubQuery(['SELECT' => 'profiles_id', 'FROM' => 'glpi_profiles_users',
        'WHERE' => ['users_id' => $serviceUser, 'is_recursive' => 0]])]]);
echo ' <button name="load_options" value="1" class="btn btn-secondary btn-sm">Show profile permissions</button></td></tr><tr><th>Permitted exact entities</th><td>';
try { $entities = Settings::entities($serviceUser, $serviceProfile); }
catch (Throwable $error) { $entities = []; echo $html($error->getMessage()); }
echo $html(implode(', ', $entities));
echo '</td></tr>';
foreach (['token' => 'Token (blank keeps current)', 'service_password' => 'Service password (blank keeps current)'] as $key => $label) {
    echo '<tr><th><label for="' . $key . '">' . $label . '</label></th><td><input id="' . $key . '" name="' . $key
        . '" type="password" autocomplete="new-password" size="60" value=""></td></tr>';
}
echo '</table><input type="hidden" name="_glpi_csrf_token" value="' . $html(Session::getNewCSRFToken()) . '"><button type="submit" name="save_settings" value="1" class="btn btn-primary btn-sm">Save settings</button></form>';

$wakeEnabled = ($settings['wake_enabled'] ?? '0') === '1';
$wakeCurrent = false;
try { $wakeCurrent = $wakeEnabled && hash_equals($settings['wake_binding'] ?? '', Settings::wakeBinding($settings)); }
catch (Throwable) {}
echo '<h3>Optional HTTPS wake</h3><p>A background worker is required. B can keep its CLI worker or receive bounded HTTPS wakes. Enabled or enforced service-account MFA is unsupported for HTTPS wake.</p>';
echo '<p>Status: ' . ($wakeCurrent ? 'Enabled' : ($wakeEnabled ? 'Binding changed; issue a new key' : 'Disabled')) . '</p>';
echo '<form method="post"><table class="tab_cadre_fixe"><tr><th><label for="wake_enabled">Allow HTTPS wake from A</label></th><td><input type="checkbox" id="wake_enabled" name="wake_enabled" value="1"' . ($wakeEnabled ? ' checked' : '') . '></td></tr></table>';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . $html(Session::getNewCSRFToken()) . '"><button type="submit" name="save_wake" value="1" class="btn btn-primary btn-sm">Save wake setting</button> ';
echo '<button type="submit" name="rotate_wake" value="1" class="btn btn-secondary btn-sm"' . ($wakeEnabled ? '' : ' disabled') . '>Rotate wake key</button> ';
echo '<button type="submit" name="revoke_wake" value="1" class="btn btn-secondary btn-sm"' . ($wakeEnabled ? '' : ' disabled') . '>Disable and revoke</button></form></fieldset>';
if ($newWakeToken !== null) {
    echo '<p><label for="new_wake_key">New wake key (shown once)</label></p><input id="new_wake_key" type="text" readonly autocomplete="off" size="72" value="' . $html($newWakeToken) . '">';
    echo '<p>Store this separate key in A\'s connection wake setting. Rotation immediately revokes the previous key.</p>';
}

echo '<p>Last worker cycle: ' . (!empty($settings['worker_seen']) ? $html(gmdate('Y-m-d H:i:s', (int) $settings['worker_seen'])) . ' UTC' : 'Never') . '</p>';
echo '<p>Reconciliation: ' . $html(in_array($settings['reconcile_status'] ?? '', ['ok', 'degraded'], true) ? $settings['reconcile_status'] : 'Not recorded')
    . '. Last health acknowledgement: ' . (!empty($settings['health_ack']) ? $html(gmdate('Y-m-d H:i:s', (int) $settings['health_ack'])) . ' UTC' : 'Never') . '</p>';
echo '<table class="tab_cadre_fixe"><tr><th>Outbox outcome</th><th>Assets</th></tr>';
$result = Outbox::sql('SELECT `last_outcome`, COUNT(*) AS total FROM `' . Outbox::TABLE . '` GROUP BY `last_outcome`');
while ($row = $DB->fetchAssoc($result)) {
    echo '<tr><td>' . $html($row['last_outcome']) . '</td><td>' . (int) $row['total'] . '</td></tr>';
}
echo '</table><p>Accepted notifications remain subject to controller processing and field policies.</p></div>';
Html::footer();
