<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\Menu;
use GlpiPlugin\Assetsync20\Plugin;

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
$connections = GlpiBConnection::loadAll();
$assetTypes = FieldMapping::assetTypes();
$sourceOptions = FieldMapping::sourceOptions();
$selectedConnectionId = trim((string) ($_POST['connection_id'] ?? $_GET['connection_id'] ?? ''));
$selectedItemtype = (string) ($_POST['itemtype'] ?? $_GET['itemtype'] ?? 'Computer');

if (!array_key_exists($selectedItemtype, $assetTypes)) {
    $selectedItemtype = 'Computer';
}

$selectedConnection = $selectedConnectionId !== '' ? GlpiBConnection::find($selectedConnectionId) : null;
$glpiBFields = [];
$glpiBFieldsLoaded = false;
$glpiBFieldsMessage = null;

if ($selectedConnection !== null) {
    $glpiBFieldsResult = GlpiBConnection::fetchNativeFields($selectedConnection, $selectedItemtype);
    $glpiBFieldsLoaded = $glpiBFieldsResult['success'];
    $glpiBFields = $glpiBFieldsResult['fields'];

    if (!$glpiBFieldsLoaded) {
        $glpiBFieldsMessage = $glpiBFieldsResult['message'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (class_exists('Session') && method_exists('Session', 'checkRight')) {
        Session::checkRight('config', defined('UPDATE') ? UPDATE : 2);
    }

    if ($selectedConnection === null) {
        $message = 'Select a GLPI B connection before saving field mappings.';
        $messageClass = 'warning';
    } elseif (!$glpiBFieldsLoaded) {
        $message = 'Field mappings were not saved because GLPI B fields could not be loaded: '
            . ($glpiBFieldsMessage ?? 'Unknown error.');
        $messageClass = 'warning';
    } else {
        $postedMappings = $_POST['field_mappings'] ?? [];
        FieldMapping::save(
            $selectedConnectionId,
            $selectedItemtype,
            is_array($postedMappings) ? $postedMappings : [],
            $glpiBFields
        );
        $message = 'Field mappings saved.';
        $messageClass = 'success';
    }
} elseif ($selectedConnection !== null && !$glpiBFieldsLoaded) {
    $message = 'GLPI B fields could not be loaded: ' . ($glpiBFieldsMessage ?? 'Unknown error.');
    $messageClass = 'warning';
}

$savedMappings = $selectedConnectionId !== '' ? FieldMapping::load($selectedConnectionId, $selectedItemtype) : [];
$fields = FieldMapping::fieldsFor($selectedItemtype);
$displayGlpiBFields = $glpiBFields;
$displayGlpiBFieldKeys = [];

foreach ($displayGlpiBFields as $glpiBField) {
    $displayGlpiBFieldKeys[$glpiBField['key']] = true;
}

foreach ($savedMappings as $savedMapping) {
    $savedGlpiBFieldKey = $savedMapping['glpi_b_field_key'] ?? '';

    if ($savedGlpiBFieldKey === '' || isset($displayGlpiBFieldKeys[$savedGlpiBFieldKey])) {
        continue;
    }

    $savedGlpiBFieldLabel = $savedMapping['glpi_b_field_label'] !== ''
        ? $savedMapping['glpi_b_field_label']
        : $savedGlpiBFieldKey;
    $displayGlpiBFields[] = [
        'key'   => $savedGlpiBFieldKey,
        'id'    => $savedMapping['glpi_b_field_id'] ?? '',
        'uid'   => $savedMapping['glpi_b_field_uid'] ?? '',
        'label' => $savedGlpiBFieldLabel,
    ];
    $displayGlpiBFieldKeys[$savedGlpiBFieldKey] = true;
}

if (class_exists('Html') && method_exists('Html', 'header')) {
    Html::header(Plugin::NAME, $_SERVER['PHP_SELF'], 'config', 'Plugin');
}

$html = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$fieldOptionLabel = static function (array $field): string {
    $label = (string) ($field['label'] ?? '');
    $uid = (string) ($field['uid'] ?? '');
    $id = (string) ($field['id'] ?? '');

    if ($uid !== '') {
        return $label . ' (' . $uid . ')';
    }

    if ($id !== '') {
        return $label . ' (#' . $id . ')';
    }

    return $label;
};

echo '<div class="center">';
echo '<h2>Field Mapping</h2>';

if ($message !== null) {
    echo '<div class="' . $html($messageClass) . '">' . $html($message) . '</div>';
}

if ($connections === []) {
    echo '<table class="tab_cadre_fixe">';
    echo '<tr><th>GLPI B connection required</th></tr>';
    echo '<tr><td class="center">No GLPI B connections saved. ';
    echo '<a href="' . $html(Menu::configUrl()) . '">Add a GLPI B connection</a>.';
    echo '</td></tr>';
    echo '</table>';
    echo '</div>';

    if (class_exists('Html') && method_exists('Html', 'footer')) {
        Html::footer();
    }

    return;
}

echo '<form method="get" action="' . $html(Menu::fieldMappingUrl()) . '">';
echo '<table class="tab_cadre_fixe">';
echo '<tr><th colspan="2">Mapping scope</th></tr>';

echo '<tr>';
echo '<td><label for="connection_id">GLPI B connection</label></td>';
echo '<td><select id="connection_id" name="connection_id">';
echo '<option value="">Select a connection</option>';
foreach ($connections as $connection) {
    $selected = $connection['id'] === $selectedConnectionId ? ' selected' : '';
    $label = $connection['name'] !== '' ? $connection['name'] : $connection['base_url'];
    echo '<option value="' . $html($connection['id']) . '"' . $selected . '>' . $html($label) . '</option>';
}
echo '</select></td>';
echo '</tr>';

echo '<tr>';
echo '<td><label for="itemtype">Asset type</label></td>';
echo '<td><select id="itemtype" name="itemtype">';
foreach ($assetTypes as $itemtype => $label) {
    $selected = $itemtype === $selectedItemtype ? ' selected' : '';
    echo '<option value="' . $html($itemtype) . '"' . $selected . '>' . $html($label) . '</option>';
}
echo '</select></td>';
echo '</tr>';

echo '<tr><td colspan="2" class="center"><button type="submit" class="submit">Load fields</button></td></tr>';
echo '</table>';
echo '</form>';

if ($selectedConnectionId === '' || $selectedConnection === null) {
    echo '<table class="tab_cadre_fixe">';
    echo '<tr><td class="center">Select a GLPI B connection to edit field mappings.</td></tr>';
    echo '</table>';
    echo '</div>';

    if (class_exists('Html') && method_exists('Html', 'footer')) {
        Html::footer();
    }

    return;
}

echo '<form method="post" action="' . $html(Menu::fieldMappingUrl()) . '">';
echo '<input type="hidden" name="connection_id" value="' . $html($selectedConnectionId) . '">';
echo '<input type="hidden" name="itemtype" value="' . $html($selectedItemtype) . '">';
echo '<table class="tab_cadre_fixe">';
echo '<tr><th colspan="4">' . $html($assetTypes[$selectedItemtype]) . ' fields</th></tr>';
echo '<tr>';
echo '<th>GLPI A field</th>';
echo '<th>GLPI A key</th>';
echo '<th>GLPI B field</th>';
echo '<th>Source of truth</th>';
echo '</tr>';

foreach ($fields as $field) {
    $fieldKey = $field['key'];
    $savedMapping = $savedMappings[$fieldKey] ?? [
        'glpi_b_field_key' => '',
        'source_of_truth'  => 'glpi_a',
    ];
    $savedGlpiBFieldKey = $savedMapping['glpi_b_field_key'] ?? '';
    $savedSource = $savedMapping['source_of_truth'] ?? 'glpi_a';

    echo '<tr>';
    echo '<td>' . $html($field['label']) . '</td>';
    echo '<td><code>' . $html($fieldKey) . '</code></td>';
    echo '<td><select name="field_mappings[' . $html($fieldKey) . '][glpi_b_field_key]"'
        . (!$glpiBFieldsLoaded ? ' disabled' : '') . '>';
    echo '<option value="">Do not map</option>';

    foreach ($displayGlpiBFields as $glpiBField) {
        $glpiBFieldKey = $glpiBField['key'];
        $selected = $glpiBFieldKey === $savedGlpiBFieldKey ? ' selected' : '';
        echo '<option value="' . $html($glpiBFieldKey) . '"' . $selected . '>'
            . $html($fieldOptionLabel($glpiBField)) . '</option>';
    }

    echo '</select></td>';
    echo '<td><select name="field_mappings[' . $html($fieldKey) . '][source_of_truth]"'
        . (!$glpiBFieldsLoaded ? ' disabled' : '') . '>';

    foreach ($sourceOptions as $sourceValue => $sourceLabel) {
        $selected = $sourceValue === $savedSource ? ' selected' : '';
        echo '<option value="' . $html($sourceValue) . '"' . $selected . '>' . $html($sourceLabel) . '</option>';
    }

    echo '</select></td>';
    echo '</tr>';
}

echo '<tr>';
echo '<td colspan="4" class="center">';

if (class_exists('Session') && method_exists('Session', 'getNewCSRFToken')) {
    echo '<input type="hidden" name="_glpi_csrf_token" value="'
        . $html(Session::getNewCSRFToken()) . '">';
}

echo '<button type="submit" name="save" value="1" class="submit"'
    . (!$glpiBFieldsLoaded ? ' disabled' : '') . '>Save</button>';
echo '</td>';
echo '</tr>';
echo '</table>';
echo '</form>';
echo '</div>';

if (class_exists('Html') && method_exists('Html', 'footer')) {
    Html::footer();
}
