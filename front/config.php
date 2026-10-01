<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\Plugin;
use GlpiPlugin\Assetsync20\Menu;
use GlpiPlugin\Assetsync20\AssetSyncCron;
use GlpiPlugin\Assetsync20\FieldMapping;
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
$editConnection = null;
$assetTypes = FieldMapping::assetTypes();

$urlWithQuery = static function (string $url, array $query): string {
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
};

$glpiFrontUrl = static function (string $page): string {
    $page = ltrim($page, '/');

    if (class_exists('Html') && method_exists('Html', 'getPrefixedUrl')) {
        try {
            return Html::getPrefixedUrl('/front/' . $page);
        } catch (Throwable) {
            // Partial GLPI bootstrap contexts can make URL helpers unavailable.
        }
    }

    return '/front/' . $page;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (class_exists('Session') && method_exists('Session', 'checkRight')) {
        Session::checkRight('config', defined('UPDATE') ? UPDATE : 2);
    }

    if (isset($_POST['save'])) {
        $connectionInput = GlpiBConnection::fromInput([
            'id'         => $_POST['id'] ?? '',
            'name'       => $_POST['name'] ?? '',
            'base_url'   => $_POST['base_url'] ?? '',
            'app_token'  => $_POST['app_token'] ?? '',
            'user_token' => $_POST['user_token'] ?? '',
            'active'     => $_POST['active'] ?? '',
        ]);
        GlpiBConnection::save($connectionInput);

        $message = 'GLPI B connection saved.';
        $messageClass = 'success';
        $editConnection = GlpiBConnection::find($connectionInput['id']);
    }

    if (isset($_POST['test'])) {
        $connection = GlpiBConnection::fromInput([
            'id'         => $_POST['id'] ?? '',
            'name'       => $_POST['name'] ?? '',
            'base_url'   => $_POST['base_url'] ?? '',
            'app_token'  => $_POST['app_token'] ?? '',
            'user_token' => $_POST['user_token'] ?? '',
            'active'     => $_POST['active'] ?? '',
        ]);
        $test = GlpiBConnection::test($connection);
        $message = $test['message'];
        $messageClass = $test['success'] ? 'success' : 'warning';
        $editConnection = $connection;
    }

    if (isset($_POST['edit'])) {
        $editConnection = GlpiBConnection::find((string) ($_POST['id'] ?? ''));
    }

    if (isset($_POST['delete'])) {
        GlpiBConnection::delete((string) ($_POST['id'] ?? ''));
        $message = 'GLPI B connection deleted.';
        $messageClass = 'success';
    }
} elseif (isset($_GET['id'])) {
    $editConnection = GlpiBConnection::find((string) ($_GET['id'] ?? ''));
}

$connections = GlpiBConnection::loadAll();
$connection = $editConnection ?? [
    'id'         => '',
    'name'       => '',
    'base_url'   => '',
    'app_token'  => '',
    'user_token' => '',
    'active'     => false,
];

if (class_exists('Html') && method_exists('Html', 'header')) {
    Html::header(Plugin::NAME, $_SERVER['PHP_SELF'], 'config', 'Plugin');
}

$html = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

echo '<div class="assetsync-page assetsync-config">';
echo '<h2>' . htmlspecialchars(Plugin::NAME, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';

if ($message !== null) {
    echo '<div class="' . $html($messageClass) . '">' . $html($message) . '</div>';
}

echo '<table class="tab_cadre_fixe">';
echo '<tr><th colspan="3">AssetSync2.0 dashboard</th></tr>';
echo '<tr>';
echo '<th>Step</th>';
echo '<th>What to open</th>';
echo '<th>Action</th>';
echo '</tr>';
echo '<tr>';
echo '<td>1</td>';
echo '<td><strong>GLPI B Connections</strong><br><small>Add the client GLPI API details, then test them.</small></td>';
echo '<td class="center"><a class="submit" href="' . $html(Menu::configUrl() . '#glpib-connections') . '">Open connections</a></td>';
echo '</tr>';
echo '<tr>';
echo '<td>2</td>';
echo '<td><strong>Entity Routes</strong><br><small>Choose which GLPI A entities and asset types sync to each GLPI B connection.</small></td>';
echo '<td class="center"><a class="submit" href="' . $html(Menu::entitySyncRoutesUrl()) . '">Open entity routes</a></td>';
echo '</tr>';
echo '<tr>';
echo '<td>3</td>';
echo '<td><strong>Field Mapping</strong><br><small>Match GLPI A fields to GLPI B fields and choose the source of truth.</small></td>';
echo '<td class="center"><a class="submit" href="' . $html(Menu::fieldMappingUrl()) . '">Open field mapping</a></td>';
echo '</tr>';
echo '<tr>';
echo '<td>4</td>';
echo '<td><strong>Billing Fields</strong><br><small>Choose GLPI A Computer fields used by Hardware and SW/SD Billing.</small></td>';
echo '<td class="center"><a class="submit" href="' . $html(Menu::billingFieldsUrl()) . '">Open billing fields</a></td>';
echo '</tr>';
echo '<tr>';
echo '<td>5</td>';
echo '<td><strong>Sync / CRON</strong><br><small>Automatic action: <code>' . $html(AssetSyncCron::TASK_NAME) . '</code></small></td>';
echo '<td class="center"><a class="submit" href="' . $html($glpiFrontUrl('crontask.php')) . '">Open automatic actions</a></td>';
echo '</tr>';
echo '</table>';

echo '<table class="tab_cadre_fixe">';
echo '<tr><th colspan="2">Quick setup checklist</th></tr>';
echo '<tr><td>1. Add a GLPI B connection.</td><td>Use the connection form below.</td></tr>';
echo '<tr><td>2. Test the connection.</td><td>Use the Test button on the saved connection row.</td></tr>';
echo '<tr><td>3. Add entity routes.</td><td>Use Entity routes for the GLPI B connection.</td></tr>';
echo '<tr><td>4. Map fields.</td><td>Use Field mappings for the GLPI B connection.</td></tr>';
echo '<tr><td>5. Run sync.</td><td>Use GLPI automatic action <code>' . $html(AssetSyncCron::TASK_NAME) . '</code>.</td></tr>';
echo '</table>';

echo '<div class="assetsync-table-scroll">';
echo '<table class="tab_cadre_fixe assetsync-wide-table" id="glpib-connections">';
echo '<tr><th colspan="9">GLPI B connections</th></tr>';
echo '<tr>';
echo '<th>Name</th>';
echo '<th>Base URL</th>';
echo '<th>Active</th>';
echo '<th>Saved app token</th>';
echo '<th>Saved user token</th>';
echo '<th colspan="4">Actions</th>';
echo '</tr>';

if ($connections === []) {
    echo '<tr><td colspan="9" class="center">No GLPI B connections saved.</td></tr>';
}

foreach ($connections as $savedConnection) {
    echo '<tr>';
    echo '<td>' . $html($savedConnection['name']) . '</td>';
    echo '<td>' . $html($savedConnection['base_url']) . '</td>';
    echo '<td>' . ($savedConnection['active'] ? 'Yes' : 'No') . '</td>';
    echo '<td>' . ($savedConnection['app_token'] !== '' ? 'Yes' : 'No') . '</td>';
    echo '<td>' . ($savedConnection['user_token'] !== '' ? 'Yes' : 'No') . '</td>';
    echo '<td class="center">';
    echo '<form method="post" action="' . $html(Menu::configUrl()) . '" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . $html($savedConnection['id']) . '">';
    if (class_exists('Session') && method_exists('Session', 'getNewCSRFToken')) {
        echo '<input type="hidden" name="_glpi_csrf_token" value="'
            . $html(Session::getNewCSRFToken()) . '">';
    }
    echo '<button type="submit" name="edit" value="1" class="submit">Edit</button> ';
    echo '<button type="submit" name="delete" value="1" class="submit">Delete</button>';
    echo '</form>';
    echo '</td>';
    echo '<td class="center">';
    echo '<form method="post" action="' . $html(Menu::configUrl()) . '" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . $html($savedConnection['id']) . '">';
    echo '<input type="hidden" name="name" value="' . $html($savedConnection['name']) . '">';
    echo '<input type="hidden" name="base_url" value="' . $html($savedConnection['base_url']) . '">';
    echo '<input type="hidden" name="active" value="' . ($savedConnection['active'] ? '1' : '') . '">';
    if (class_exists('Session') && method_exists('Session', 'getNewCSRFToken')) {
        echo '<input type="hidden" name="_glpi_csrf_token" value="'
            . $html(Session::getNewCSRFToken()) . '">';
    }
    echo '<button type="submit" name="test" value="1" class="submit">Test</button>';
    echo '</form>';
    echo '</td>';
    echo '<td class="center">';
    echo '<a class="submit" href="' . $html($urlWithQuery(Menu::entitySyncRoutesUrl(), [
        'connection_id' => $savedConnection['id'],
    ])) . '">Entity routes</a>';
    echo '</td>';
    echo '<td class="center">';
    echo '<a class="submit" href="' . $html($urlWithQuery(Menu::fieldMappingUrl(), [
        'connection_id' => $savedConnection['id'],
    ])) . '">Field mappings</a>';
    echo '</td>';
    echo '</tr>';
}

echo '</table>';
echo '</div>';

echo '<form method="post" action="' . $html(Menu::configUrl()) . '">';
echo '<input type="hidden" name="id" value="' . $html($connection['id']) . '">';
echo '<table class="tab_cadre_fixe">';
echo '<tr><th colspan="2">' . ($connection['id'] !== '' ? 'Edit GLPI B connection' : 'Add GLPI B connection') . '</th></tr>';

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
if ($connection['id'] !== '') {
    echo ' <a href="' . $html(Menu::configUrl()) . '" class="submit">Add new</a>';
}
echo '</td>';
echo '</tr>';
echo '</table>';
echo '</form>';

if ($connection['id'] !== '') {
    $connectionLabel = $connection['name'] !== '' ? $connection['name'] : $connection['base_url'];
    echo '<table class="tab_cadre_fixe">';
    echo '<tr><th colspan="2">Setup for ' . $html($connectionLabel) . '</th></tr>';
    echo '<tr>';
    echo '<td>Entity routes</td>';
    echo '<td><a class="submit" href="' . $html($urlWithQuery(Menu::entitySyncRoutesUrl(), [
        'connection_id' => $connection['id'],
    ])) . '">Manage entity routes</a></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td>Field mappings</td>';
    echo '<td>';
    foreach ($assetTypes as $itemtype => $label) {
        echo '<a class="submit" href="' . $html($urlWithQuery(Menu::fieldMappingUrl(), [
            'connection_id' => $connection['id'],
            'itemtype'      => $itemtype,
        ])) . '">' . $html($label) . '</a> ';
    }
    echo '</td>';
    echo '</tr>';
    echo '</table>';
}

echo '</div>';

if (class_exists('Html') && method_exists('Html', 'footer')) {
    Html::footer();
}
