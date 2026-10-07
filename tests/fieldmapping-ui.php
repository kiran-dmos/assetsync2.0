<?php

declare(strict_types=1);

// Reuse the strict in-process HTTP fake. No server or real GLPI bootstrap is loaded.
require __DIR__ . '/remote-catalog-pagination.php';

use GlpiPlugin\Assetsync20\FieldMapping;
use GlpiPlugin\Assetsync20\GlpiBConnection;

if (!class_exists('Session')) {
    class Session
    {
        public static bool $canUpdate = true;
        public static array $checked = [];
        public static function checkRight(string $right, int $level): void
        {
            self::$checked[] = [$right, $level];
            if ($level === 2 && !self::$canUpdate) {
                throw new RuntimeException('Update denied.');
            }
        }
        public static function getNewCSRFToken(): string { return 'test-csrf'; }
    }
}

$uiChecks = 0;
function uiCheck(bool $ok, string $message): void
{
    global $uiChecks;
    $uiChecks++;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function renderMappingPage(?array $post = null, bool $metadataAvailable = true): string
{
    $_GET = ['connection_id' => 'ui', 'itemtype' => 'Computer'];
    $_POST = $post === null ? [] : $post + ['connection_id' => 'ui', 'itemtype' => 'Computer', 'save' => '1'];
    $_SERVER['REQUEST_METHOD'] = $post === null ? 'GET' : 'POST';
    $_SERVER['PHP_SELF'] = '/plugins/assetsync20/front/fieldmapping.php';
    $options = Search::getOptions('Computer');
    CatalogCurl::$steps = [
        catalogStep('initSession', ['session_token' => 'test']),
        catalogStep('listSearchOptions/Computer', $options, $metadataAvailable ? 200 : 503),
        catalogStep('listSearchOptions/Computer', $options),
        catalogStep('killSession'),
    ];
    $source = file_get_contents(__DIR__ . '/../front/fieldmapping.php');
    $source = str_replace("include GLPI_ROOT . '/inc/includes.php';", '', $source);
    $source = str_replace('__DIR__', var_export(dirname(__DIR__) . '/front', true), $source);
    ob_start();
    try {
        eval(substr($source, strlen('<?php')));
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
        uiCheck(CatalogCurl::$steps === [], 'Only expected fake discovery requests occur.');
    }
}

Config::$values = [];
Search::$nativeOptions = [];
PluginFieldsContainer::$options = [];
GlpiBConnection::save(['id' => 'ui', 'name' => 'UI', 'active' => true, 'base_url' => 'https://private.invalid',
    'app_token' => 'private-app', 'user_token' => 'private-user']);
$row = ['glpi_a_field_key' => 'name', 'glpi_b_field_key' => 'comment', 'source_of_truth' => 'glpi_a'];
FieldMapping::save('ui', 'Computer', [$row], FieldMapping::discoverFields('Computer', Search::getOptions('Computer'), true));
$before = Config::$values['plugin:assetsync20']['field_mappings'];
$html = renderMappingPage();
uiCheck(str_contains($html, 'field_mappings[0][glpi_a_field_key]') && str_contains($html, 'field_mappings[0][glpi_b_field_key]'), 'A and B have indexed selectors.');
uiCheck(str_contains($html, '<optgroup label="Native">') && str_contains($html, '<optgroup label="Custom">'), 'Selectors group endpoints.');
uiCheck(str_contains($html, 'title="Remove mapping"') && str_contains($html, 'ti ti-trash'), 'Remove has existing icon and accessible name.');
uiCheck(strpos($html, 'name="mapping_form_complete"') > strpos($html, 'name="field_mappings[0][source_of_truth]"')
    && strpos($html, 'name="_glpi_csrf_token"') > strpos($html, 'name="mapping_form_complete"'), 'Completion and core CSRF token follow rows.');
uiCheck(in_array(['config', 1], Session::$checked, true), 'Page enforces READ.');
uiCheck(str_contains($html, '/css/assetsync20.css?v=' . hash_file('sha256', __DIR__ . '/../public/css/assetsync20.css')), 'Mapping stylesheet is loaded from the served asset path with a content hash.');
$css = file_get_contents(__DIR__ . '/../public/css/assetsync20.css');
uiCheck(str_contains($css, 'position: relative;') && str_contains($css, 'overflow-x: auto;'), 'Wide mapping table scrolling is locally contained.');
uiCheck(str_contains($css, '.assetsync-mapping .assetsync-mapping-table td { display: table-cell;'), 'GLPI narrow-layout cell stacking is overridden only for the mapping table.');

$invalid = ['glpi_a_field_key' => '<script>alert(1)</script>', 'glpi_b_field_key' => 'old missing key', 'source_of_truth' => 'bad authority'];
$complete = ['mapping_form_complete' => '1', 'mapping_row_count' => '1'];
$html = renderMappingPage($complete + ['field_mappings' => [$invalid]]);
uiCheck(!str_contains($html, 'Field mappings saved.') && str_contains($html, 'Mapping row 1'), 'Validation failure never reports success.');
uiCheck(str_contains($html, 'old missing key') && str_contains($html, 'bad authority'), 'Failed POST retains invalid selections.');
uiCheck(!str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;'), 'Stale keys are escaped.');
uiCheck($before === Config::$values['plugin:assetsync20']['field_mappings'], 'Rejected POST preserves scope.');
$html = renderMappingPage(['field_mappings' => [$row]]);
uiCheck(str_contains($html, 'Incomplete mapping form') && $before === Config::$values['plugin:assetsync20']['field_mappings'], 'Truncated submission cannot replace scope.');
$html = renderMappingPage(['mapping_form_complete' => '1', 'mapping_row_count' => '2', 'field_mappings' => [$row]]);
uiCheck(str_contains($html, 'Incomplete mapping form'), 'Row count mismatch blocks.');
$html = renderMappingPage($complete + ['field_mappings' => 'malformed']);
uiCheck(str_contains($html, 'Invalid mapping rows') && $before === Config::$values['plugin:assetsync20']['field_mappings'], 'Non-array submission cannot clear scope.');
$html = renderMappingPage($complete + ['field_mappings' => [$row]], false);
uiCheck(!str_contains($html, 'Field mappings saved.') && $before === Config::$values['plugin:assetsync20']['field_mappings'], 'Unavailable current B discovery prevents save.');
Session::$canUpdate = false;
$html = renderMappingPage($complete + ['field_mappings' => [$row]]);
uiCheck(str_contains($html, 'Update denied') && $before === Config::$values['plugin:assetsync20']['field_mappings'], 'UPDATE denial prevents save.');
Session::$canUpdate = true;
$html = renderMappingPage(['mapping_form_complete' => '1', 'mapping_row_count' => '0']);
uiCheck(str_contains($html, 'Field mappings saved.') && FieldMapping::load('ui', 'Computer') === [], 'Complete deliberate remove-all succeeds.');

Search::$nativeOptions = [815 => ['name' => 'UUID', 'table' => 'glpi_computers', 'field' => 'uuid',
    'uid' => 'Computer.uuid', 'datatype' => 'string']];
$reserved = ['glpi_a_field_key' => '815', 'glpi_b_field_key' => '991', 'glpi_b_field_id' => '991',
    'glpi_b_field_uid' => 'Computer.uuid', 'glpi_b_field_label' => 'Old <UUID>', 'source_of_truth' => 'both'];
Config::$values['plugin:assetsync20']['field_mappings'] = json_encode(['ui' => ['Computer' => [
    '815' => array_diff_key($reserved, ['glpi_a_field_key' => true]),
    'name' => array_diff_key($row, ['glpi_a_field_key' => true]),
]]], JSON_THROW_ON_ERROR);
$before = Config::$values['plugin:assetsync20']['field_mappings'];
$html = renderMappingPage();
uiCheck(str_contains($html, 'Reserved mapping conflict:') && str_contains($html, FieldMapping::UUID_MAPPING_CONFLICT), 'Retained UUID mapping has an explicit visible conflict.');
uiCheck(str_contains($html, 'type="hidden" name="field_mappings[0][glpi_a_field_key]" value="815"')
    && str_contains($html, 'type="hidden" name="field_mappings[0][glpi_b_field_key]" value="991"')
    && str_contains($html, 'name="field_mappings[0][source_of_truth]" value="both"'), 'Reserved form retains original endpoint aliases and authority.');
uiCheck(!str_contains($html, 'name="field_mappings[0][glpi_a_field_key]" required')
    && str_contains($html, 'value="uuid" disabled'), 'Reserved row is read-only except removal and native UUID is disabled for new rows.');
uiCheck(str_contains($html, 'Old &lt;UUID&gt;') && $before === Config::$values['plugin:assetsync20']['field_mappings'], 'Rendering escapes retained labels and does not rewrite configuration.');
$changedOrdinary = $row;
$changedOrdinary['source_of_truth'] = 'glpi_b';
$html = renderMappingPage(['mapping_form_complete' => '1', 'mapping_row_count' => '2',
    'field_mappings' => [$reserved, $changedOrdinary]]);
$stored = json_decode(Config::$values['plugin:assetsync20']['field_mappings'], true)['ui']['Computer'];
uiCheck(str_contains($html, 'Field mappings saved.') && $stored['version'] === 2
    && $stored['rows'][0] == $reserved && $stored['rows'][1]['source_of_truth'] === 'glpi_b', 'Unrelated page save migrates to v2 without changing reserved row identities or authority.');
uiCheck(str_contains($html, 'Reserved mapping conflict:'), 'Reserved conflict remains visible after successful unrelated save.');
$before = Config::$values['plugin:assetsync20']['field_mappings'];
$tampered = $reserved;
$tampered['source_of_truth'] = 'glpi_a';
$html = renderMappingPage(['mapping_form_complete' => '1', 'mapping_row_count' => '2',
    'field_mappings' => [$tampered, $changedOrdinary]]);
uiCheck(str_contains($html, 'New or altered native UUID mappings are not allowed')
    && !str_contains($html, 'Field mappings saved.') && $before === Config::$values['plugin:assetsync20']['field_mappings'], 'Tampering with readonly UUID authority is rejected without clearing mappings.');
$html = renderMappingPage($complete + ['field_mappings' => [$changedOrdinary]]);
uiCheck(str_contains($html, 'Field mappings saved.') && count(FieldMapping::load('ui', 'Computer')) === 1, 'Explicit reserved row removal succeeds.');

echo 'Mapping UI tests passed (' . $uiChecks . " checks).\n";
