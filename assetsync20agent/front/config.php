<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20agent\ExecutionSettings;

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}

include GLPI_ROOT . '/inc/includes.php';
require_once __DIR__ . '/../src/autoload.php';

Session::checkCentralAccess();
Session::checkRight('config', UPDATE);
header('Cache-Control: no-store');

$message = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        ExecutionSettings::save($_POST);
        $message = 'Authorised-representative settings saved.';
    } catch (Throwable) {
        $message = 'Settings rejected. Check the Agent B service-user ID.';
    }
}

$execution = ExecutionSettings::load();

$previous = $_SESSION['glpicrontimer'] ?? null;
$_SESSION['glpicrontimer'] = time();

try {
    Html::header('AssetSync2.0 Agent', $_SERVER['PHP_SELF'], 'config', 'Plugin');
} finally {
    if ($previous === null) {
        unset($_SESSION['glpicrontimer']);
    } else {
        $_SESSION['glpicrontimer'] = $previous;
    }
}

$html = static fn ($text): string => htmlspecialchars(
    (string) $text,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

echo '<h2>AssetSync2.0 Agent</h2><p>' . $html($message) . '</p>';
echo '<h3>Authorised representative</h3>';
echo '<p>Agent B performs approved GLPI B asset writes through GLPI’s internal item API. '
    . 'GLPI A authenticates using the GLPI B App Token and the User Token of '
    . '<code>assetsync-agent-b</code>.</p>';

echo '<form method="post"><table class="tab_cadre_fixe">';
echo '<tr><th><label for="service_user_id">assetsync-agent-b user ID</label></th><td>';
echo '<input id="service_user_id" name="service_user_id" type="number" min="1" value="'
    . $html($execution['service_user_id'] ?? '') . '">';
echo '<br><small>The GLPI B account recorded in History for AssetSync updates.</small>';
echo '</td></tr>';
echo '</table>';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . $html(Session::getNewCSRFToken()) . '">';
echo '<button type="submit" class="submit">Save Agent B settings</button>';
echo '</form>';

echo '<p>Command receiver URL: <code>/plugins/assetsync20agent/front/agent-command.php</code>.</p>';
echo '<p>GLPI A polls confirmed GLPI B asset links through its own worker. '
    . 'No callback URL, registration, notification token, or Agent B worker is required.</p>';

Html::footer();