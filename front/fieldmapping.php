<?php

declare(strict_types=1);

use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\EntitySyncRoute;
use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\Menu;
use GlpiPlugin\Assetsync20\Plugin;
use GlpiPlugin\Assetsync20\RestWork;

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
$textMode = $selectedConnection !== null && GlpiBConnection::isAgent($selectedConnection)
    && ($selectedConnection['agent_transport'] ?? '') === 'rest_records' && $selectedItemtype === 'Computer';
$textAction = (string) ($_POST['text_catalog_action'] ?? '');
$textEntities = [];
$textEntity = null;
$textPins = [];
$textCatalogStatus = null;
$textAttemptStatus = null;
$textSelectionReady = false;
$textAttempt = null;
$textScope = '';
if ($textMode) {
    foreach (EntitySyncRoute::loadAll() as $route) {
        if ($route['active'] && $route['glpi_b_connection_id'] === $selectedConnectionId
            && in_array('Computer', $route['asset_types'], true) && ctype_digit($route['glpi_b_target_entity_id'])) {
            $textEntities[(int) $route['glpi_b_target_entity_id']] = $route['glpi_b_target_entity_name'] ?: 'Entity #' . $route['glpi_b_target_entity_id'];
        }
    }
    $requestedEntity = $_POST['text_target_entity'] ?? $_GET['text_target_entity'] ?? array_key_first($textEntities);
    if ($requestedEntity !== null && preg_match('/^(0|[1-9][0-9]*)$/D', (string) $requestedEntity)
        && array_key_exists((int) $requestedEntity, $textEntities)) { $textEntity = (int) $requestedEntity; }
    if ($textEntity !== null) {
        $textScope = hash('sha256', $selectedConnectionId . ':' . $textEntity);
        if (isset($_SESSION['assetsync_text_flash'][$textScope])) {
            $message = $_SESSION['assetsync_text_flash'][$textScope];
            unset($_SESSION['assetsync_text_flash'][$textScope]);
        }
        $view = $_SESSION['assetsync_text_catalog'][$textScope] ?? [];
        try {
            foreach ($view['pins'] ?? [] as $key => $pin) {
                if (($view['pin_expires'][$key] ?? 0) < time()) { unset($view['pins'][$key], $view['pin_expires'][$key]); }
            }
            if (!empty($view['work_id'])) {
                $textCatalogStatus = RestWork::catalogStatus($selectedConnectionId, $view['work_id']);
                if ($textCatalogStatus['state'] === 'observed') {
                    foreach ($textCatalogStatus['fields'] as $pin) {
                        if (($textCatalogStatus['observed_at'] ?? 0) + 300 < time()) { continue; }
                        $view['pins'][$pin['capture_key']] = $pin;
                        $view['pin_expires'][$pin['capture_key']] = $textCatalogStatus['observed_at'] + 300;
                    }
                    $_SESSION['assetsync_text_catalog'][$textScope] = $view;
                }
            }
            $textPins = $view['pins'] ?? [];
            $textAttempt = $_SESSION['assetsync_text_save'][$textScope] ?? null;
            if (is_array($textAttempt)) {
                $textAttemptStatus = RestWork::catalogStatus($selectedConnectionId, $textAttempt['work_id']);
                if ($textAttemptStatus['state'] === 'observed' && ($textAttemptStatus['observed_at'] ?? 0) + 300 >= time()) {
                    $textSelectionReady = $textAttemptStatus['fields'] !== [];
                    $verifiedKeys = [];
                    foreach ($textAttemptStatus['fields'] as $field) {
                        $pin = $field['descriptor'] ?? null;
                        if (!is_array($pin) || ($field['reason'] ?? null) !== null
                            || ($field['capture_key'] ?? null) !== ($pin['capture_key'] ?? null)) { $textSelectionReady = false; continue; }
                        $verifiedKeys[] = $pin['key'];
                        $textPins[$pin['capture_key']] = $pin;
                    }
                    $expectedKeys = [];
                    foreach ($textAttempt['rows'] as $row) {
                        if (!FieldMapping::isReservedUuidRow('Computer', $row)) { $expectedKeys[] = $row['glpi_b_field_key'] ?? ''; }
                    }
                    sort($expectedKeys, SORT_STRING);
                    sort($verifiedKeys, SORT_STRING);
                    $textSelectionReady = $textSelectionReady && $expectedKeys === $verifiedKeys
                        && count(array_unique($verifiedKeys)) === count($verifiedKeys);
                }
            }
        } catch (Throwable) { $message = 'The saved B field observation is unavailable. Request the fields again.'; $messageClass = 'warning'; }
    }
}

if ($selectedConnection !== null) {
    if (GlpiBConnection::isAgent($selectedConnection) && isset($_POST['refresh_agent'])) {
        Session::checkRight('config', UPDATE);
        try {
            global $DB;
            $queued = false;
            foreach ($DB->request(['FROM' => \GlpiPlugin\Assetsync20\AgentTask::PAIRS,
                'WHERE' => ['connection_id' => $selectedConnectionId, 'itemtype' => $selectedItemtype, 'confirmed' => 1],
                'ORDER' => 'local_id DESC', 'LIMIT' => 32]) as $pair) {
                if (!\GlpiPlugin\Assetsync20\AgentTask::model($selectedItemtype)->can((int) $pair['local_id'], READ)
                    || !Session::haveAccessToEntity((int) $pair['local_entity'])
                    || \GlpiPlugin\Assetsync20\AgentTask::outstanding($selectedConnectionId, $pair)) { continue; }
                try { \GlpiPlugin\Assetsync20\AgentTask::scope($pair); }
                catch (RuntimeException) { continue; }
                \GlpiPlugin\Assetsync20\AgentTask::inspect($selectedConnectionId, $pair['id']);
                $queued = true;
                break;
            }
            if (!$queued) { throw new RuntimeException('No readable confirmed pair is available for inspection. Existing outstanding tasks were preserved.'); }
            $message = 'Inspection queued; awaiting B agent.';
        } catch (Throwable $error) { $message = $error->getMessage(); $messageClass = 'warning'; }
    }
    $glpiBFieldsResult = GlpiBConnection::fetchNativeFields($selectedConnection, $selectedItemtype);
    $glpiBFieldsLoaded = $glpiBFieldsResult['success'];
    $glpiBFields = $glpiBFieldsResult['fields'];

    if (!$glpiBFieldsLoaded) {
        $glpiBFieldsMessage = $glpiBFieldsResult['message'];
    }
}

// Catalog descriptors are for selection only. Saving requires a new selected-field receipt.
foreach ($textPins as $pin) {
    $glpiBFields = array_values(array_filter($glpiBFields, static fn (array $field): bool => $field['key'] !== $pin['key']));
    $glpiBFields[] = ['key' => $pin['key'], 'label' => $pin['key'], 'group' => $pin['kind'] === 'native' ? 'Native' : 'Custom',
        'type' => $pin['type'], 'supported' => true, 'reason' => ''];
    $glpiBFieldsLoaded = true;
}

$savedMappings = [];
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['refresh_agent']) && $textAction === '';
try {
    $savedMappings = $selectedConnectionId !== '' ? FieldMapping::load($selectedConnectionId, $selectedItemtype) : [];
    if (!$posted && $textAction === '' && is_array($textAttempt) && time() - $textAttempt['at'] <= 300) { $savedMappings = $textAttempt['rows']; }
    if ($textAction !== '') {
        Session::checkRight('config', UPDATE);
        if (!$textMode || $textEntity === null) { throw new RuntimeException('Select an enabled Computer route before requesting B fields.'); }
        if (in_array($textAction, ['native', 'fields', 'more'], true)) {
            $after = $textAction === 'more' ? ($textCatalogStatus['next_after_id'] ?? null) : 0;
            if ($textAction === 'more' && !is_int($after)) { throw new RuntimeException('There is no next field page.'); }
            $selector = $textAction === 'native' ? ['kind' => 'native'] : ['kind' => 'fields', 'after_id' => $after];
            $id = RestWork::requestCatalog($selectedConnectionId, $textEntity, $selector);
            $_SESSION['assetsync_text_catalog'][$textScope] = ['work_id' => $id, 'pins' => $textPins,
                'pin_expires' => $_SESSION['assetsync_text_catalog'][$textScope]['pin_expires'] ?? []];
            $_SESSION['assetsync_text_catalog'] = array_slice($_SESSION['assetsync_text_catalog'], -32, null, true);
            $message = 'B field request queued. Refresh this page after the background worker runs.';
        } elseif ($textAction === 'selected') {
            $rows = $_POST['field_mappings'] ?? null;
            if (!is_array($rows) || ($_POST['mapping_form_complete'] ?? '') !== '1'
                || (int) ($_POST['mapping_row_count'] ?? -1) !== count($rows)) {
                throw new RuntimeException('The mapping form is incomplete. Nothing was changed.');
            }
            foreach ($rows as $row) {
                if (!is_array($row)) { throw new RuntimeException('Invalid mapping row. Nothing was changed.'); }
            }
            $ordinary = array_values(array_filter($rows, static fn (array $row): bool => !FieldMapping::isReservedUuidRow('Computer', $row, $glpiBFields)));
            if ($ordinary === [] || count($ordinary) > 8) {
                throw new RuntimeException('Select one to eight complete Text Both mapping rows. Nothing was changed; extra rows are never ignored.');
            }
            $captureKeys = [];
            foreach ($ordinary as $row) {
                if (($row['source_of_truth'] ?? '') !== 'both') { throw new RuntimeException('Select Both for every row before verifying these fields.'); }
                $matches = array_values(array_filter($textPins, static fn (array $pin): bool => $pin['key'] === ($row['glpi_b_field_key'] ?? null)));
                if (count($matches) !== 1) { throw new RuntimeException('Request B fields and select each destination from that observation first.'); }
                $captureKeys[] = $matches[0]['capture_key'];
            }
            if (count(array_unique($captureKeys)) !== count($captureKeys)) { throw new RuntimeException('Each mapping needs a different B destination field. Nothing was changed.'); }
            sort($captureKeys, SORT_STRING);
            $nonce = bin2hex(random_bytes(16));
            $id = RestWork::requestCatalog($selectedConnectionId, $textEntity,
                ['kind' => 'selected', 'capture_keys' => $captureKeys], $nonce);
            $textAttempt = ['nonce' => $nonce, 'work_id' => $id, 'rows' => array_values($rows), 'at' => time()];
            $_SESSION['assetsync_text_save'][$textScope] = $textAttempt;
            $_SESSION['assetsync_text_save'] = array_slice($_SESSION['assetsync_text_save'], -8, null, true);
            $savedMappings = array_values($rows);
            $message = 'Field verification queued. Refresh this page, then save the verified selection.';
        } elseif ($textAction === 'cancel') {
            unset($_SESSION['assetsync_text_save'][$textScope]);
            $textAttempt = null;
            $message = 'Field selection cancelled. Saved mappings and queued work were not changed.';
        } else { throw new RuntimeException('Unknown field verification action. Nothing was saved.'); }
        $_SESSION['assetsync_text_flash'][$textScope] = $message;
        $_SESSION['assetsync_text_flash'] = array_slice($_SESSION['assetsync_text_flash'], -32, null, true);
        header('Location: ' . $urlWithQuery(Menu::fieldMappingUrl(), ['connection_id' => $selectedConnectionId,
            'itemtype' => $selectedItemtype, 'text_target_entity' => $textEntity]), true, 303);
        return;
    } elseif ($posted) {
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
        $attemptNonce = (string) ($_POST['text_save_attempt'] ?? '');
        if ($attemptNonce !== '') {
            if (!$textMode || $textEntity === null || !is_array($textAttempt)
                || !hash_equals($textAttempt['nonce'], $attemptNonce) || $textAttempt['rows'] !== $savedMappings
                || time() - $textAttempt['at'] > 300) {
                throw new RuntimeException('The field selection changed or expired. Verify it again before saving.');
            }
            if (!$textSelectionReady) { throw new RuntimeException('Not all selected fields were verified. Nothing was saved. Verify the complete selection again.'); }
            FieldMapping::savePinnedText($selectedConnectionId, $selectedItemtype, $textAttempt['rows'], $textEntity,
                $textAttempt['work_id'], $textAttempt['nonce']);
            unset($_SESSION['assetsync_text_save'][$textScope]);
            $textAttempt = null;
        } else { FieldMapping::save($selectedConnectionId, $selectedItemtype, $savedMappings, $glpiBFields); }
        $savedMappings = FieldMapping::load($selectedConnectionId, $selectedItemtype);
        $message = 'Field mappings saved.';
        $messageClass = 'success';
    } elseif ($selectedConnection !== null && !$glpiBFieldsLoaded && $message === null) {
        throw new RuntimeException('GLPI B fields could not be loaded: ' . ($glpiBFieldsMessage ?? 'Unknown error.'));
    }
} catch (Throwable $error) {
    $message = $error->getMessage();
    $messageClass = 'warning';
}
$fields = FieldMapping::fieldsFor($selectedItemtype);
if ($selectedConnection !== null && GlpiBConnection::isAgent($selectedConnection)) {
    $sourceOptions = array_intersect_key($sourceOptions, array_flip(['glpi_a', 'glpi_b', 'both']));
    $fields = array_values(array_filter($fields, static fn (array $field): bool => in_array($field['key'], ['name', 'comment'], true)
        || ($field['group'] === 'Custom' && in_array($field['type'], $textMode ? ['text', 'textarea', 'yesno', 'date'] : ['text', 'yesno', 'date'], true))));
}

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
if ($selectedConnection !== null && GlpiBConnection::isAgent($selectedConnection)) {
    echo $textMode ? '<p>Text Both supports up to eight native or custom text/textarea mappings on an existing confirmed Computer pair. Each field uses its own original edit time. Large values may exceed the batch size limit; creation is not supported here.</p>'
        : '<p>Both is available for matching native Name or Comments on confirmed existing pairs. Custom Both and creation with Both are unsupported.</p>';
    echo '<form method="post"><input type="hidden" name="connection_id" value="' . $html($selectedConnectionId) . '">'
        . '<input type="hidden" name="itemtype" value="' . $html($selectedItemtype) . '">'
        . '<input type="hidden" name="_glpi_csrf_token" value="' . $html(Session::getNewCSRFToken()) . '">'
        . '<button class="submit" name="refresh_agent" value="1">Refresh B observation</button></form>';
}

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

if ($textMode) {
    echo '<form method="post"><table class="tab_cadre_fixe"><tr><th colspan="2">B field discovery</th></tr>';
    echo '<tr><td><label for="text_target_entity">B route entity</label></td><td><select id="text_target_entity" name="text_target_entity">';
    foreach ($textEntities as $entityId => $label) {
        echo '<option value="' . (int) $entityId . '"' . ($entityId === $textEntity ? ' selected' : '') . '>' . $html($label) . '</option>';
    }
    echo '</select></td></tr><tr><td colspan="2" class="center">';
    foreach (['native' => 'Request native fields', 'fields' => 'Request custom fields'] as $action => $label) {
        echo '<button class="submit" name="text_catalog_action" value="' . $action . '"' . ($textEntity === null ? ' disabled' : '') . '>' . $label . '</button> ';
    }
    if (is_int($textCatalogStatus['next_after_id'] ?? null)) {
        echo '<button class="submit" name="text_catalog_action" value="more">More custom fields</button> ';
    }
    echo '<a href="' . $html($urlWithQuery(Menu::fieldMappingUrl(), ['connection_id' => $selectedConnectionId,
        'itemtype' => $selectedItemtype, 'text_target_entity' => $textEntity])) . '">Refresh field status</a>';
    echo '<input type="hidden" name="connection_id" value="' . $html($selectedConnectionId) . '">';
    echo '<input type="hidden" name="itemtype" value="' . $html($selectedItemtype) . '">';
    echo '<input type="hidden" name="_glpi_csrf_token" value="' . $html(Session::getNewCSRFToken()) . '">';
    echo '</td></tr>';
    if ($textCatalogStatus !== null) {
        $label = match ($textCatalogStatus['state']) {
            'observed' => 'B field page received: ' . count($textCatalogStatus['fields']) . ' fields.',
            'blocked' => 'B fields are unavailable or access was denied. Request the fields again after correcting the configuration.',
            'expired' => 'B field request expired. Request the fields again.',
            default => 'B field request waiting for the background worker.',
        };
        echo '<tr><td colspan="2">' . $html($label) . '</td></tr>';
    }
    if ($textAttempt !== null) {
        $label = match ($textAttemptStatus['state'] ?? '') {
            'observed' => $textSelectionReady ? 'All selected fields verified. Save to apply these mappings.'
                : 'Selected-field verification failed for one or more fields. Nothing was saved. Check field availability and permissions, then verify the complete selection again.',
            'blocked' => 'Selected-field verification failed. Check field availability and permissions, then verify the selection again.',
            'expired' => 'Selected-field verification expired. Verify the selection again.',
            default => 'Selected-field verification pending. Saved mappings have not changed.',
        };
        echo '<tr><td colspan="2">' . $html($label) . ' <button class="submit" name="text_catalog_action" value="cancel">Cancel selection</button></td></tr>';
    }
    echo '</table></form>';
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
$renderRow = static function (array $row, string $index) use ($fields, $glpiBFields, $sourceOptions, $selectedItemtype, $html, $scalar, $renderSelect): void {
    $prefix = 'field_mappings[' . $index . ']';
    echo '<tr class="assetsync-mapping-row">';
    if (FieldMapping::isReservedUuidRow($selectedItemtype, $row, $glpiBFields)) {
        // Carry unchanged identities, not rediscovered aliases. Save verifies them against stored rows.
        $raw = $row['_raw'] ?? $row;
        echo '<td><code>' . $html($scalar($raw['glpi_a_field_key'] ?? '')) . '</code>';
        echo '<small class="assetsync-mapping-error">Reserved mapping conflict: ' . $html(FieldMapping::UUID_MAPPING_CONFLICT) . '</small>';
        foreach (['glpi_a_field_key', 'glpi_b_field_key', 'glpi_b_field_id', 'glpi_b_field_uid', 'glpi_b_field_label', 'source_of_truth'] as $key) {
            echo '<input type="hidden" name="' . $html($prefix . '[' . $key . ']') . '" value="' . $html($scalar($raw[$key] ?? '')) . '">';
        }
        echo '</td><td><code>' . $html($scalar($raw['glpi_b_field_key'] ?? '')) . '</code></td>';
        echo '<td>' . $html($scalar($raw['source_of_truth'] ?? '')) . '</td>';
        echo '<td class="assetsync-mapping-remove"><button type="button" class="btn btn-sm btn-ghost-secondary" data-remove-row title="Remove mapping" aria-label="Remove mapping"><i class="ti ti-trash" aria-hidden="true"></i></button></td></tr>';
        return;
    }
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
if ($textMode && $textEntity !== null) {
    echo '<input type="hidden" name="text_target_entity" value="' . $textEntity . '">';
    if ($textAttempt !== null) { echo '<input type="hidden" name="text_save_attempt" value="' . $html($textAttempt['nonce']) . '">'; }
}
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
if ($textMode && $textEntity !== null) { echo '<button type="submit" class="submit" name="text_catalog_action" value="selected">Verify Text Both fields</button>'; }
$saveDisabled = !$glpiBFieldsLoaded || ($textAttempt !== null && (!$textSelectionReady || time() - $textAttempt['at'] > 300));
echo '<button type="submit" name="save" value="1" class="submit"' . ($saveDisabled ? ' disabled' : '') . '>Save</button></div>';
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
