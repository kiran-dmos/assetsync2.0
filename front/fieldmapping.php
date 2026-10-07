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

$urlWithQuery = static function (string $url, array $query): string {
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
};

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

$savedMappings = [];
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
try {
    $savedMappings = $selectedConnectionId !== '' ? FieldMapping::load($selectedConnectionId, $selectedItemtype) : [];
    if ($posted) {
        Session::checkRight('config', defined('UPDATE') ? UPDATE : 2);
        $postedMappings = $_POST['field_mappings'] ?? [];
        if (!is_array($postedMappings)) {
            throw new RuntimeException('Invalid mapping rows. Nothing was saved.');
        }
        $savedMappings = array_values($postedMappings);
        if ($selectedConnection === null) {
            throw new RuntimeException('Select a GLPI B connection before saving field mappings.');
        }
        if (!$glpiBFieldsLoaded) {
            throw new RuntimeException('GLPI B fields could not be loaded: ' . ($glpiBFieldsMessage ?? 'Unknown error.'));
        }
        if (($_POST['mapping_form_complete'] ?? '') !== '1'
            || (int) ($_POST['mapping_row_count'] ?? -1) !== count($savedMappings)) {
            throw new RuntimeException('Incomplete mapping form. Nothing was saved.');
        }
        FieldMapping::save($selectedConnectionId, $selectedItemtype, $savedMappings, $glpiBFields);
        $savedMappings = FieldMapping::load($selectedConnectionId, $selectedItemtype);
        $message = 'Field mappings saved.';
        $messageClass = 'success';
    } elseif ($selectedConnection !== null && !$glpiBFieldsLoaded) {
        throw new RuntimeException('GLPI B fields could not be loaded: ' . ($glpiBFieldsMessage ?? 'Unknown error.'));
    }
} catch (Throwable $error) {
    $message = $error->getMessage();
    $messageClass = 'warning';
}
$fields = FieldMapping::fieldsFor($selectedItemtype);

if (class_exists('Html') && method_exists('Html', 'header')) {
    Html::header(Plugin::NAME, $_SERVER['PHP_SELF'], 'config', 'Plugin');
}

$html = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$stylesheet = dirname(Menu::fieldMappingUrl(), 2) . '/css/assetsync20.css?v=' . hash_file('sha256', __DIR__ . '/../public/css/assetsync20.css');
echo '<link rel="stylesheet" href="' . $html($stylesheet) . '">';

echo '<div class="assetsync-page assetsync-mapping">';
echo '<h2>Field Mapping</h2>';

if ($selectedConnection !== null) {
    echo '<p class="assetsync-actions"><a class="submit" href="' . $html($urlWithQuery(Menu::configUrl(), [
        'id' => $selectedConnectionId,
    ])) . '">Back to GLPI B connection setup</a></p>';
}

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

$scalar = static fn ($value): string => is_scalar($value) ? (string) $value : '[invalid value]';
$renderSelect = static function (array $catalog, string $key, string $name, string $side) use ($html): void {
    try {
        $selected = FieldMapping::selectedField($catalog, $key);
    } catch (RuntimeException) {
        $selected = null;
    }
    $selectedKey = $selected['key'] ?? $key;
    echo '<select aria-label="' . $html($side . ' field') . '" name="' . $html($name) . '" required>';
    echo '<option value="">Select a field</option>';
    if ($key !== '' && $selected === null) {
        echo '<option value="' . $html($key) . '" selected>Unavailable: ' . $html($key) . '</option>';
    }
    foreach (['Native', 'Custom'] as $group) {
        echo '<optgroup label="' . $group . '">';
        foreach ($catalog as $field) {
            if ($field['group'] !== $group) {
                continue;
            }
            $isSelected = $field['key'] === $selectedKey;
            $reason = $field['supported'] ? '' : ' - Unsupported: ' . $field['reason'];
            echo '<option value="' . $html($field['key']) . '"' . ($isSelected ? ' selected' : '')
                . (!$field['supported'] && !$isSelected ? ' disabled' : '') . '>'
                . $html($field['label'] . ' (' . $field['key'] . ')' . $reason) . '</option>';
        }
        echo '</optgroup>';
    }
    echo '</select>';
    if ($key !== '' && ($selected === null || !$selected['supported'])) {
        echo '<small class="assetsync-mapping-error">' . $html($selected['reason'] ?? 'Saved field is unavailable; reselection is required.') . '</small>';
    }
};
$renderRow = static function (array $row, string $index) use ($fields, $glpiBFields, $sourceOptions, $html, $scalar, $renderSelect): void {
    $prefix = 'field_mappings[' . $index . ']';
    echo '<tr class="assetsync-mapping-row">';
    echo '<td>';
    $renderSelect($fields, $scalar($row['glpi_a_field_key'] ?? ''), $prefix . '[glpi_a_field_key]', 'GLPI A');
    echo '</td><td>';
    // A legacy search ID is resolved only by B metadata, never by A's search options.
    $bKey = $scalar($row['glpi_b_field_key'] ?? '');
    if (!empty($row['_legacy']) && $bKey !== '' && ctype_digit($bKey) && !empty($row['glpi_b_field_uid'])) {
        $bKey = $scalar($row['glpi_b_field_uid']);
    }
    $renderSelect($glpiBFields, $bKey, $prefix . '[glpi_b_field_key]', 'GLPI B');
    echo '</td><td><select aria-label="Source of truth" name="' . $html($prefix . '[source_of_truth]') . '" required>';
    $source = $scalar($row['source_of_truth'] ?? 'glpi_a');
    if (!isset($sourceOptions[$source])) {
        echo '<option value="' . $html($source) . '" selected>Invalid: ' . $html($source) . '</option>';
    }
    foreach ($sourceOptions as $value => $label) {
        echo '<option value="' . $value . '"' . ($source === $value ? ' selected' : '') . '>' . $html($label) . '</option>';
    }
    echo '</select></td><td class="assetsync-mapping-remove"><button type="button" class="btn btn-sm btn-ghost-secondary" data-remove-row title="Remove mapping" aria-label="Remove mapping"><i class="ti ti-trash" aria-hidden="true"></i></button></td>';
    echo '</tr>';
};
if (array_filter($savedMappings, static fn ($row) => is_array($row) && !empty($row['_legacy']))) {
    echo '<p class="warning">Legacy mappings have not been acknowledged under the current validation rules.</p>';
}
echo '<form id="assetsync-mapping-form" method="post" action="' . $html(Menu::fieldMappingUrl()) . '">';
echo '<input type="hidden" name="connection_id" value="' . $html($selectedConnectionId) . '">';
echo '<input type="hidden" name="itemtype" value="' . $html($selectedItemtype) . '">';
echo '<div class="assetsync-table-scroll"><table class="tab_cadre_fixe assetsync-mapping-table">';
echo '<colgroup><col><col><col class="assetsync-authority-col"><col class="assetsync-remove-col"></colgroup>';
echo '<thead><tr><th>GLPI A field</th><th>GLPI B field</th><th>Source of truth</th><th><span class="visually-hidden">Actions</span></th></tr></thead>';
echo '<tbody id="assetsync-mapping-rows">';
foreach ($savedMappings as $index => $row) {
    $renderRow(is_array($row) ? $row : ['glpi_a_field_key' => '[invalid row]'], (string) $index);
}
echo '</tbody></table></div>';
echo '<template id="assetsync-mapping-template">';
$renderRow([], '__index__');
echo '</template>';
echo '<div class="assetsync-mapping-actions"><button type="button" class="btn btn-secondary" id="assetsync-add-mapping"><i class="ti ti-plus" aria-hidden="true"></i> Add mapping</button>';
echo '<button type="submit" name="save" value="1" class="submit"' . (!$glpiBFieldsLoaded ? ' disabled' : '') . '>Save</button></div>';
echo '<input type="hidden" name="mapping_row_count" value="' . count($savedMappings) . '">';
echo '<input type="hidden" name="mapping_form_complete" value="1">';
if (class_exists('Session') && method_exists('Session', 'getNewCSRFToken')) {
    echo '<input type="hidden" name="_glpi_csrf_token" value="' . $html(Session::getNewCSRFToken()) . '">';
}
echo '</form></div>';
?>
<script>
(() => {
    const form = document.getElementById('assetsync-mapping-form');
    const rows = document.getElementById('assetsync-mapping-rows');
    const template = document.getElementById('assetsync-mapping-template');
    let nextIndex = rows.children.length;
    document.getElementById('assetsync-add-mapping').addEventListener('click', () => {
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[name]').forEach(input => {
            input.name = input.name.replace('__index__', String(nextIndex));
        });
        nextIndex++;
        rows.appendChild(fragment);
        rows.lastElementChild.querySelector('select').focus();
    });
    rows.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-row]');
        if (button) button.closest('tr').remove();
    });
    form.addEventListener('submit', () => {
        form.elements.mapping_row_count.value = String(rows.children.length);
    });
})();
</script>
<?php

if (class_exists('Html') && method_exists('Html', 'footer')) {
    Html::footer();
}
