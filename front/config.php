<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\Plugin;
use GlpiPlugin\Assetsync20\Menu;
use GlpiPlugin\Assetsync20\GlpiBConnection;

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}

include GLPI_ROOT . '/inc/includes.php';
require_once __DIR__ . '/../src/autoload.php';

if (class_exists('Session') && method_exists('Session', 'checkRight')) {
    Session::checkRight('config', defined('READ') ? READ : 1);
}

$message = null;
$messageClass = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (class_exists('Session') && method_exists('Session', 'checkRight')) {
        Session::checkRight('config', defined('UPDATE') ? UPDATE : 2);
    }

    if (isset($_POST['save'])) {
        GlpiBConnection::save([
            'name'       => $_POST['name'] ?? '',
            'base_url'   => $_POST['base_url'] ?? '',
            'app_token'  => $_POST['app_token'] ?? '',
            'user_token' => $_POST['user_token'] ?? '',
            'active'     => $_POST['active'] ?? '',
        ]);

        $message = 'GLPI B connection saved.';
        $messageClass = 'success';
    }

    if (isset($_POST['test'])) {
        $connection = GlpiBConnection::fromInput([
            'name'       => $_POST['name'] ?? '',
            'base_url'   => $_POST['base_url'] ?? '',
            'app_token'  => $_POST['app_token'] ?? '',
            'user_token' => $_POST['user_token'] ?? '',
            'active'     => $_POST['active'] ?? '',
        ]);
        $test = GlpiBConnection::test($connection);
        $message = $test['message'];
        $messageClass = $test['success'] ? 'success' : 'warning';
    }
}

$connection = GlpiBConnection::load();

if (class_exists('Html') && method_exists('Html', 'header')) {
    Html::header(Plugin::NAME, $_SERVER['PHP_SELF'], 'config', 'Plugin');
}

$html = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

echo '<div class="center">';
echo '<h2>' . htmlspecialchars(Plugin::NAME, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';

if ($message !== null) {
    echo '<div class="' . $html($messageClass) . '">' . $html($message) . '</div>';
}

echo '<form method="post" action="' . $html(Menu::configUrl()) . '">';
echo '<table class="tab_cadre_fixe">';
echo '<tr><th colspan="2">GLPI B connection</th></tr>';

echo '<tr>';
echo '<td><label for="name">Connection name</label></td>';
echo '<td><input type="text" id="name" name="name" value="' . $html($connection['name']) . '" size="60"></td>';
echo '</tr>';

echo '<tr>';
echo '<td><label for="base_url">Base URL</label></td>';
echo '<td><input type="url" id="base_url" name="base_url" value="' . $html($connection['base_url']) . '" size="60" placeholder="https://glpi-b.example.com"></td>';
echo '</tr>';

echo '<tr>';
echo '<td><label for="app_token">App token</label></td>';
echo '<td><input type="password" id="app_token" name="app_token" value="" size="60" autocomplete="new-password"></td>';
echo '</tr>';

echo '<tr>';
echo '<td>Saved app token</td>';
echo '<td>' . ($connection['app_token'] !== '' ? 'Yes' : 'No') . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td><label for="user_token">User token</label></td>';
echo '<td><input type="password" id="user_token" name="user_token" value="" size="60" autocomplete="new-password"></td>';
echo '</tr>';

echo '<tr>';
echo '<td>Saved user token</td>';
echo '<td>' . ($connection['user_token'] !== '' ? 'Yes' : 'No') . '</td>';
echo '</tr>';

echo '<tr>';
echo '<td><label for="active">Active</label></td>';
echo '<td><input type="checkbox" id="active" name="active" value="1"'
    . ($connection['active'] ? ' checked' : '') . '></td>';
echo '</tr>';

echo '<tr>';
echo '<td colspan="2" class="center">';

if (class_exists('Session') && method_exists('Session', 'getNewCSRFToken')) {
    echo '<input type="hidden" name="_glpi_csrf_token" value="'
        . $html(Session::getNewCSRFToken()) . '">';
}

echo '<button type="submit" name="save" value="1" class="submit">Save</button> ';
echo '<button type="submit" name="test" value="1" class="submit">Test connection</button>';
echo '</td>';
echo '</tr>';
echo '</table>';
echo '</form>';
echo '</div>';

if (class_exists('Html') && method_exists('Html', 'footer')) {
    Html::footer();
}
