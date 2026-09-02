<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\Menu;
use GlpiPlugin\Assetsync20\Plugin;

if (!defined('GLPI_ROOT')) {
    $glpiRoot = dirname(__DIR__, 3);
    if (!is_file($glpiRoot . '/inc/includes.php') && is_file('/var/www/glpi/inc/includes.php')) {
        $glpiRoot = '/var/www/glpi';
    }

    define('GLPI_ROOT', $glpiRoot);
}

$glpiIncludes = GLPI_ROOT . '/inc/includes.php';
if (is_file($glpiIncludes)) {
    include $glpiIncludes;
}
require_once __DIR__ . '/../src/autoload.php';

if (class_exists('Session') && method_exists('Session', 'checkRight')) {
    Session::checkRight('config', defined('READ') ? READ : 1);
}

$message = null;
$messageClass = 'info';
$editRoute = null;

$requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($requestMethod === 'POST') {
    if (class_exists('Session') && method_exists('Session', 'checkRight')) {
        Session::checkRight('config', defined('UPDATE') ? UPDATE : 2);
    }

    if (isset($_POST['save'])) {
        $route = EntitySyncRoute::fromInput([
            'id'                         => $_POST['id'] ?? '',
            'name'                       => $_POST['name'] ?? '',
            'glpi_b_connection_id'       => $_POST['glpi_b_connection_id'] ?? '',
            'glpi_a_source_entity_id'    => $_POST['glpi_a_source_entity_id'] ?? '',
            'glpi_a_source_entity_name'  => $_POST['glpi_a_source_entity_name'] ?? '',
            'glpi_b_target_entity_id'    => $_POST['glpi_b_target_entity_id'] ?? '',
            'glpi_b_target_entity_name'  => $_POST['glpi_b_target_entity_name'] ?? '',
            'asset_types'                => $_POST['asset_types'] ?? [],
            'include_child_entities'     => $_POST['include_child_entities'] ?? '',
            'active'                     => $_POST['active'] ?? '',
        ]);

        EntitySyncRoute::save($route);
        $message = 'Entity sync route saved.';
        $messageClass = 'success';
        $editRoute = EntitySyncRoute::find($route['id']);
    }

    if (isset($_POST['edit'])) {
        $editRoute = EntitySyncRoute::find((string) ($_POST['id'] ?? ''));
    }

    if (isset($_POST['delete'])) {
        EntitySyncRoute::delete((string) ($_POST['id'] ?? ''));
        $message = 'Entity sync route deleted.';
        $messageClass = 'success';
    }
}

$connections = GlpiBConnection::loadAll();
$routes = EntitySyncRoute::loadAll();
$assetTypes = FieldMapping::assetTypes();
$conflicts = EntitySyncRoute::findConfigConflicts();
$route = $editRoute ?? [
    'id'                         => '',
    'name'                       => '',
    'glpi_b_connection_id'       => '',
    'glpi_a_source_entity_id'    => '',
    'glpi_a_source_entity_name'  => '',
    'glpi_b_target_entity_id'    => '',
    'glpi_b_target_entity_name'  => '',
    'asset_types'                => [],
    'include_child_entities'     => false,
    'active'                     => true,
];

if (class_exists('Html') && method_exists('Html', 'header')) {
    Html::header(Plugin::NAME, $_SERVER['PHP_SELF'], 'config', 'Plugin');
}

$html = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$connectionLabels = [];
foreach ($connections as $connection) {
    $connectionLabels[$connection['id']] = $connection['name'] !== ''
        ? $connection['name']
        : $connection['base_url'];
}

$assetTypeLabels = static function (array $selectedTypes) use ($assetTypes): string {
    $labels = [];

    foreach ($selectedTypes as $assetType) {
        if (isset($assetTypes[$assetType])) {
            $labels[] = $assetTypes[$assetType];
        }
    }

    return implode(', ', $labels);
};

$routeLabel = static function (string $routeId) use ($routes): string {
    foreach ($routes as $savedRoute) {
        if ($savedRoute['id'] === $routeId) {
            return $savedRoute['name'] !== '' ? $savedRoute['name'] : $savedRoute['id'];
        }
    }

    return $routeId;
};

$csrfInput = static function () use ($html): void {
    if (class_exists('Session') && method_exists('Session', 'getNewCSRFToken')) {
        echo '<input type="hidden" name="_glpi_csrf_token" value="'
            . $html(Session::getNewCSRFToken()) . '">';
    }
};

$entityDropdown = static function (array $selectedRoute) use ($html): void {
    if (class_exists('Dropdown') && method_exists('Dropdown', 'show')) {
        try {
            $selectedEntityId = (string) ($selectedRoute['glpi_a_source_entity_id'] ?? '');
            $selectedValue = ctype_digit($selectedEntityId) ? (int) $selectedEntityId : 0;
            \Dropdown::show('Entity', [
                'name'                => 'glpi_a_source_entity_id',
                'value'               => $selectedValue,
                'display_emptychoice' => true,
            ]);
            return;
        } catch (Throwable) {
            // Fall through to the plain input for older or partial GLPI contexts.
        }
    }

    echo '<input type="text" id="glpi_a_source_entity_id" name="glpi_a_source_entity_id" value="'
        . $html((string) ($selectedRoute['glpi_a_source_entity_id'] ?? '')) . '" size="20">';
};

echo '<div class="center">';
echo '<h2>Entity Sync Routes</h2>';

if ($message !== null) {
    echo '<div class="' . $html($messageClass) . '">' . $html($message) . '</div>';
}

if ($conflicts !== []) {
    echo '<div class="warning">One or more active routes overlap at the same source entity depth for the same GLPI B connection and asset type.</div>';
    echo '<table class="tab_cadre_fixe">';
    echo '<tr><th colspan="4">Route conflicts</th></tr>';
    echo '<tr>';
    echo '<th>GLPI B connection</th>';
    echo '<th>Asset type</th>';
    echo '<th>GLPI A source entity id</th>';
    echo '<th>Routes</th>';
    echo '</tr>';

    foreach ($conflicts as $conflict) {
        $conflictRouteLabels = [];
        foreach ($conflict['route_ids'] as $routeId) {
            $conflictRouteLabels[] = $routeLabel($routeId);
        }

        echo '<tr>';
        echo '<td>' . $html($connectionLabels[$conflict['glpi_b_connection_id']] ?? $conflict['glpi_b_connection_id']) . '</td>';
        echo '<td>' . $html($assetTypes[$conflict['itemtype']] ?? $conflict['itemtype']) . '</td>';
        echo '<td>' . $html($conflict['source_entity_id']) . '</td>';
        echo '<td>' . $html(implode(', ', $conflictRouteLabels)) . '</td>';
        echo '</tr>';
    }

    echo '</table>';
}

echo '<table class="tab_cadre_fixe">';
echo '<tr><th colspan="8">Saved routes</th></tr>';
echo '<tr>';
echo '<th>Name</th>';
echo '<th>GLPI B connection</th>';
echo '<th>GLPI A source entity</th>';
echo '<th>GLPI B target entity</th>';
echo '<th>Asset types</th>';
echo '<th>Include children</th>';
echo '<th>Active</th>';
echo '<th>Actions</th>';
echo '</tr>';

if ($routes === []) {
    echo '<tr><td colspan="8" class="center">No entity sync routes saved.</td></tr>';
}

foreach ($routes as $savedRoute) {
    $sourceEntity = $savedRoute['glpi_a_source_entity_name'] !== ''
        ? $savedRoute['glpi_a_source_entity_name'] . ' (#' . $savedRoute['glpi_a_source_entity_id'] . ')'
        : $savedRoute['glpi_a_source_entity_id'];
    $targetEntity = $savedRoute['glpi_b_target_entity_name'] !== ''
        ? $savedRoute['glpi_b_target_entity_name'] . ' (#' . $savedRoute['glpi_b_target_entity_id'] . ')'
        : $savedRoute['glpi_b_target_entity_id'];

    echo '<tr>';
    echo '<td>' . $html($savedRoute['name']) . '</td>';
    echo '<td>' . $html($connectionLabels[$savedRoute['glpi_b_connection_id']] ?? $savedRoute['glpi_b_connection_id']) . '</td>';
    echo '<td>' . $html($sourceEntity) . '</td>';
    echo '<td>' . $html($targetEntity) . '</td>';
    echo '<td>' . $html($assetTypeLabels($savedRoute['asset_types'])) . '</td>';
    echo '<td>' . ($savedRoute['include_child_entities'] ? 'Yes' : 'No') . '</td>';
    echo '<td>' . ($savedRoute['active'] ? 'Yes' : 'No') . '</td>';
    echo '<td class="center">';
    echo '<form method="post" action="' . $html(Menu::entitySyncRoutesUrl()) . '" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . $html($savedRoute['id']) . '">';
    $csrfInput();
    echo '<button type="submit" name="edit" value="1" class="submit">Edit</button> ';
    echo '<button type="submit" name="delete" value="1" class="submit">Delete</button>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
}

echo '</table>';

if ($connections === []) {
    echo '<table class="tab_cadre_fixe">';
    echo '<tr><th>GLPI B connection required</th></tr>';
    echo '<tr><td class="center">No GLPI B connections saved. ';
    echo '<a href="' . $html(Menu::configUrl()) . '">Add a GLPI B connection</a> before creating entity sync routes.';
    echo '</td></tr>';
    echo '</table>';
    echo '</div>';

    if (class_exists('Html') && method_exists('Html', 'footer')) {
        Html::footer();
    }

    return;
}

echo '<form method="post" action="' . $html(Menu::entitySyncRoutesUrl()) . '">';
echo '<input type="hidden" name="id" value="' . $html($route['id']) . '">';
echo '<table class="tab_cadre_fixe">';
echo '<tr><th colspan="2">' . ($route['id'] !== '' ? 'Edit route' : 'Add route') . '</th></tr>';

echo '<tr>';
echo '<td><label for="name">Route name</label></td>';
echo '<td><input type="text" id="name" name="name" value="' . $html($route['name']) . '" size="60" required></td>';
echo '</tr>';

echo '<tr>';
echo '<td><label for="glpi_b_connection_id">GLPI B connection</label></td>';
echo '<td><select id="glpi_b_connection_id" name="glpi_b_connection_id" required>';
echo '<option value="">Select a connection</option>';
foreach ($connections as $connection) {
    $selected = $connection['id'] === $route['glpi_b_connection_id'] ? ' selected' : '';
    echo '<option value="' . $html($connection['id']) . '"' . $selected . '>'
        . $html($connectionLabels[$connection['id']]) . '</option>';
}
echo '</select></td>';
echo '</tr>';

echo '<tr>';
echo '<td><label for="glpi_a_source_entity_id">GLPI A source entity</label></td>';
echo '<td>';
$entityDropdown($route);
echo '</td>';
echo '</tr>';

echo '<tr>';
echo '<td><label for="glpi_a_source_entity_name">GLPI A source entity snapshot</label></td>';
echo '<td><input type="text" id="glpi_a_source_entity_name" name="glpi_a_source_entity_name" value="'
    . $html($route['glpi_a_source_entity_name']) . '" size="60"></td>';
echo '</tr>';

echo '<tr>';
echo '<td><label for="glpi_b_target_entity_id">GLPI B target entity id</label></td>';
echo '<td><input type="text" id="glpi_b_target_entity_id" name="glpi_b_target_entity_id" value="'
    . $html($route['glpi_b_target_entity_id']) . '" size="20" required></td>';
echo '</tr>';

echo '<tr>';
echo '<td><label for="glpi_b_target_entity_name">GLPI B target entity name</label></td>';
echo '<td><input type="text" id="glpi_b_target_entity_name" name="glpi_b_target_entity_name" value="'
    . $html($route['glpi_b_target_entity_name']) . '" size="60"></td>';
echo '</tr>';

echo '<tr>';
echo '<td>Asset types</td>';
echo '<td>';
foreach ($assetTypes as $assetType => $label) {
    $checked = in_array($assetType, $route['asset_types'], true) ? ' checked' : '';
    echo '<label style="display:inline-block;margin-right:1em">';
    echo '<input type="checkbox" name="asset_types[]" value="' . $html($assetType) . '"' . $checked . '> ';
    echo $html($label);
    echo '</label>';
}
echo '</td>';
echo '</tr>';

echo '<tr>';
echo '<td><label for="include_child_entities">Include child entities</label></td>';
echo '<td><input type="checkbox" id="include_child_entities" name="include_child_entities" value="1"'
    . ($route['include_child_entities'] ? ' checked' : '') . '></td>';
echo '</tr>';

echo '<tr>';
echo '<td><label for="active">Active</label></td>';
echo '<td><input type="checkbox" id="active" name="active" value="1"'
    . ($route['active'] ? ' checked' : '') . '></td>';
echo '</tr>';

echo '<tr>';
echo '<td colspan="2" class="center">';
$csrfInput();
echo '<button type="submit" name="save" value="1" class="submit">Save</button>';
if ($route['id'] !== '') {
    echo ' <a href="' . $html(Menu::entitySyncRoutesUrl()) . '" class="submit">Add new</a>';
}
echo '</td>';
echo '</tr>';
echo '</table>';
echo '</form>';
echo '</div>';

if (class_exists('Html') && method_exists('Html', 'footer')) {
    Html::footer();
}
