<?php

declare(strict_types=1);

const READ = 1;
const UPDATE = 2;
$checks = 0;
function textPageCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
class Session
{
    public static bool $read = true;
    public static bool $update = true;
    public static function checkRight($right, $mode): void
    {
        if (!self::$read || ($mode === UPDATE && !self::$update)) { throw new RuntimeException('Access denied.'); }
    }
    public static function getNewCSRFToken(): string { return 'test-csrf'; }
}
class TextPageConnection
{
    public static function find($id): ?array { return $id === 'owned' ? ['id' => 'owned', 'name' => 'Owned connection', 'base_url' => 'https://local.example.test', 'agent_transport' => 'rest_records'] : null; }
    public static function loadAll(): array { return [self::find('owned')]; }
    public static function isAgent($connection): bool { return true; }
    public static function fetchNativeFields($connection, $type): array { return ['success' => true, 'fields' => [TextPageMapping::field('name')]]; }
}
class TextPageMapping
{
    public static array $saved = [['glpi_a_field_key' => 'name', 'glpi_b_field_key' => 'name', 'source_of_truth' => 'both']];
    public static array $writes = [];
    public static function assetTypes(): array { return ['Computer' => 'Computers']; }
    public static function sourceOptions(): array { return ['glpi_a' => 'GLPI A', 'glpi_b' => 'GLPI B', 'both' => 'Both']; }
    public static function load(...$args): array { return self::$saved; }
    public static function field($key): array { return ['key' => $key, 'label' => $key, 'group' => 'Native', 'type' => 'text', 'supported' => true, 'reason' => '']; }
    public static function fieldsFor($type): array { return [self::field('name'), self::field('comment')]; }
    public static function selectedField($catalog, $key): ?array { foreach ($catalog as $field) { if ($field['key'] === $key) { return $field; } } return null; }
    public static function isReservedUuidRow($type, $row, $catalog = []): bool { return ($row['glpi_a_field_key'] ?? '') === 'uuid'; }
    public static function save(...$args): void { self::$writes[] = ['legacy', $args]; }
    public static function savePinnedText(...$args): void
    {
        if ($args[4] !== $args[5]) { throw new RuntimeException('Selected receipt ID must equal the save nonce.'); }
        self::$writes[] = ['pinned', $args]; self::$saved = $args[2];
    }
}
class TextPageRoutes
{
    public static function loadAll(): array { return [['active' => true, 'glpi_b_connection_id' => 'owned', 'asset_types' => ['Computer'], 'glpi_b_target_entity_id' => '7', 'glpi_b_target_entity_name' => 'Owned B entity']]; }
}
class TextPageRest
{
    public static array $requests = [];
    public static array $results = [];
    public static function requestCatalog($connection, $entity, $selector, $attempt = null): string
    {
        $id = $selector['kind'] === 'selected' ? $attempt : md5('catalog-' . count(self::$requests));
        self::$requests[] = [$connection, $entity, $selector, $attempt, $id];
        self::$results[$id] = ['work_id' => $id, 'state' => 'pending', 'fields' => [], 'next_after_id' => null,
            'complete' => false, 'observed_at' => null, 'expires' => time() + 300, 'reason' => ''];
        return $id;
    }
    public static function catalogStatus($connection, $id): array { return self::$results[$id] ?? throw new RuntimeException('Unavailable.'); }
}
class TextPageMenu
{
    public static function fieldMappingUrl(): string { return '/plugins/assetsync20/front/fieldmapping.php'; }
    public static function configUrl(): string { return '/plugins/assetsync20/front/config.php'; }
}
class TextPagePlugin { public const NAME = 'AssetSync2.0'; }
foreach (['FieldMapping' => TextPageMapping::class, 'GlpiBConnection' => TextPageConnection::class,
    'EntitySyncRoute' => TextPageRoutes::class, 'RestWork' => TextPageRest::class,
    'Menu' => TextPageMenu::class, 'Plugin' => TextPagePlugin::class] as $name => $class) {
    class_alias($class, 'GlpiPlugin\\Assetsync20\\' . $name);
}
class TextPageBootstrap
{
    public $context;
    private int $position = 0;
    public function stream_open($path, $mode, $options, &$opened): bool { return $mode === 'rb' && str_ends_with($path, '/inc/includes.php'); }
    public function stream_read($count): string { $part = substr('<?php ', $this->position, $count); $this->position += strlen($part); return $part; }
    public function stream_eof(): bool { return $this->position >= 6; }
    public function stream_stat(): array { return []; }
    public function stream_set_option(...$args): bool { return false; }
}
stream_wrapper_register('textpage', TextPageBootstrap::class);
define('GLPI_ROOT', 'textpage://root');
$_SESSION = [];
function textPageRender(array $post = [], array $get = []): string
{
    $_POST = $post;
    $_GET = array_replace(['connection_id' => 'owned', 'itemtype' => 'Computer'], $get);
    $_SERVER = ['REQUEST_METHOD' => $post === [] ? 'GET' : 'POST', 'PHP_SELF' => '/plugins/assetsync20/front/fieldmapping.php'];
    ob_start();
    try { include __DIR__ . '/../front/fieldmapping.php'; return ob_get_contents(); }
    finally { ob_end_clean(); }
}
$base = ['connection_id' => 'owned', 'itemtype' => 'Computer', 'text_target_entity' => '7'];
$html = textPageRender();
textPageCheck(str_contains($html, 'Request native fields') && str_contains($html, 'Verify Text Both fields'), 'Discovery and explicit activation controls render.');
textPageCheck(TextPageRest::$requests === [] && TextPageMapping::$writes === [], 'GET never enqueues discovery or saves configuration.');
textPageCheck(str_contains($html, '_glpi_csrf_token') && str_contains($html, 'test-csrf'), 'All command forms include the stock CSRF token.');
textPageRender($base + ['text_catalog_action' => 'native']);
textPageCheck(count(TextPageRest::$requests) === 1 && TextPageRest::$requests[0][2] === ['kind' => 'native'], 'Explicit POST only enqueues native discovery.');
$id = TextPageRest::$requests[0][4];
textPageCheck(str_contains(textPageRender(), 'waiting for the background worker') && count(TextPageRest::$requests) === 1, 'Refresh reads pending local status without another request.');
TextPageRest::$results[$id] = array_replace(TextPageRest::$results[$id], ['state' => 'observed', 'fields' => [
    ['key' => 'name', 'capture_key' => 'native:name', 'kind' => 'native', 'type' => 'text']], 'observed_at' => time(), 'complete' => true]);
$html = textPageRender();
textPageCheck(str_contains($html, '1 fields.') && count(TextPageRest::$requests) === 1, 'Fresh local descriptor populates the selector without network.');
$rows = [['glpi_a_field_key' => 'comment', 'glpi_b_field_key' => 'name', 'source_of_truth' => 'both']];
$form = $base + ['field_mappings' => $rows, 'mapping_form_complete' => '1', 'mapping_row_count' => '1'];
textPageRender($form + ['text_catalog_action' => 'selected']);
$scope = hash('sha256', 'owned:7');
$attempt = $_SESSION['assetsync_text_save'][$scope];
textPageCheck(count(TextPageRest::$requests) === 2 && TextPageRest::$requests[1][2] === ['kind' => 'selected', 'capture_keys' => ['native:name']]
    && TextPageRest::$requests[1][3] === $attempt['nonce'] && $attempt['work_id'] === $attempt['nonce']
    && TextPageMapping::$writes === [], 'Selection queues fresh verification using the save nonce as its exact work identity, not a mapping save.');
$html = textPageRender();
textPageCheck(str_contains($html, 'value="comment" selected') && str_contains($html, 'verification pending'), 'GET retains the pending submitted selection.');
foreach (['blocked' => 'verification failed', 'expired' => 'verification expired'] as $state => $label) {
    TextPageRest::$results[$attempt['work_id']]['state'] = $state;
    $html = textPageRender();
    textPageCheck(str_contains($html, $label) && !str_contains($html, 'verification pending')
        && count(TextPageRest::$requests) === 2 && TextPageMapping::$writes === [], 'Terminal selection failure needs fresh verification, not waiting or another automatic request.');
}
TextPageRest::$results[$attempt['work_id']] = array_replace(TextPageRest::$results[$attempt['work_id']], ['state' => 'observed',
    'fields' => [['capture_key' => 'native:name', 'descriptor' => TextPageRest::$results[$id]['fields'][0], 'reason' => null]], 'observed_at' => time(), 'complete' => true]);
textPageRender($form + ['text_save_attempt' => $attempt['nonce'], 'save' => '1', 'glpi_b_pin' => ['forged' => true]]);
textPageCheck(count(TextPageMapping::$writes) === 1 && TextPageMapping::$writes[0][0] === 'pinned'
    && TextPageMapping::$writes[0][1] === ['owned', 'Computer', $rows, 7, $attempt['work_id'], $attempt['nonce']], 'Save uses server rows and authenticated work reference, never caller pin JSON.');
textPageCheck(!isset($_SESSION['assetsync_text_save'][$scope]), 'Successful save consumes only the session attempt.');
$before = TextPageMapping::$writes;
$html = textPageRender($form + ['text_save_attempt' => str_repeat('a', 32), 'save' => '1']);
textPageCheck(str_contains($html, 'Verify it again') && TextPageMapping::$writes === $before, 'Unknown save nonce does not fall back to legacy saving.');
$requests = count(TextPageRest::$requests);
$html = textPageRender(array_replace($base, ['text_target_entity' => '999', 'text_catalog_action' => 'native']));
textPageCheck(str_contains($html, 'Select an enabled Computer route') && count(TextPageRest::$requests) === $requests, 'Wrong route entity never queues discovery.');
$html = textPageRender(array_replace($form, ['text_catalog_action' => 'selected', 'mapping_row_count' => '2']));
textPageCheck(str_contains($html, 'form is incomplete') && count(TextPageRest::$requests) === $requests, 'Truncated form cannot request or save field selection.');

// Multiple mappings use one selected receipt, not one request per field.
$pins = [
    ['key' => 'name', 'capture_key' => 'native:name', 'kind' => 'native', 'type' => 'text'],
    ['key' => 'Computer.PluginFieldsComputerOther.summary', 'capture_key' => 'fields:21', 'kind' => 'fields', 'type' => 'textarea'],
];
$_SESSION['assetsync_text_catalog'][$scope] = ['pins' => array_column($pins, null, 'capture_key'),
    'pin_expires' => array_fill_keys(array_column($pins, 'capture_key'), time() + 300)];
$multiRows = [$rows[0], ['glpi_a_field_key' => 'name', 'glpi_b_field_key' => $pins[1]['key'], 'source_of_truth' => 'both']];
$multiForm = $base + ['field_mappings' => $multiRows, 'mapping_form_complete' => '1', 'mapping_row_count' => '2'];
textPageRender($multiForm + ['text_catalog_action' => 'selected']);
$multiAttempt = $_SESSION['assetsync_text_save'][$scope];
textPageCheck(count(TextPageRest::$requests) === $requests + 1
    && TextPageRest::$requests[$requests][2] === ['kind' => 'selected', 'capture_keys' => ['fields:21', 'native:name']], 'Multiple different destinations are verified in one sorted selected-key request.');
$multiResult = &TextPageRest::$results[$multiAttempt['work_id']];
$multiResult = array_replace($multiResult, ['state' => 'observed', 'observed_at' => time(), 'complete' => true,
    'fields' => [['capture_key' => 'fields:21', 'descriptor' => null, 'reason' => 'field_missing'],
        ['capture_key' => 'native:name', 'descriptor' => $pins[0], 'reason' => null]]]);
$html = textPageRender();
textPageCheck(str_contains($html, 'verification failed for one or more fields') && str_contains($html, 'class="submit" disabled>Save'), 'Observed is not success when one selected descriptor is unavailable.');
$before = TextPageMapping::$writes;
$html = textPageRender($multiForm + ['text_save_attempt' => $multiAttempt['nonce'], 'save' => '1']);
textPageCheck(str_contains($html, 'Not all selected fields were verified') && TextPageMapping::$writes === $before, 'Partial verification cannot save a subset or consume the attempt.');
$multiResult['fields'] = [['capture_key' => 'native:name', 'descriptor' => $pins[0], 'reason' => null]];
textPageCheck(str_contains(textPageRender(), 'verification failed for one or more fields'), 'Missing receipt members cannot claim complete verification.');
$multiResult['fields'] = [['capture_key' => 'fields:21', 'descriptor' => $pins[1], 'reason' => null],
    ['capture_key' => 'native:name', 'descriptor' => $pins[0], 'reason' => null]];
$html = textPageRender();
textPageCheck(str_contains($html, 'All selected fields verified') && !str_contains($html, 'class="submit" disabled>Save'), 'Save becomes available only after every selected member is verified.');
textPageRender($multiForm + ['text_save_attempt' => $multiAttempt['nonce'], 'save' => '1']);
textPageCheck(count(TextPageMapping::$writes) === count($before) + 1 && TextPageMapping::$writes[array_key_last(TextPageMapping::$writes)][1][2] === $multiRows,
    'One complete save retains all different mapping rows and their authority.');
$requests = count(TextPageRest::$requests);
$tooManyRows = array_fill(0, 9, $rows[0]);
$html = textPageRender($base + ['field_mappings' => $tooManyRows, 'mapping_form_complete' => '1', 'mapping_row_count' => '9', 'text_catalog_action' => 'selected']);
textPageCheck(str_contains($html, 'one to eight') && count(TextPageRest::$requests) === $requests, 'Ninth mapping rejects the complete request before queuing; no silent truncation.');
$html = textPageRender($base + ['field_mappings' => [$rows[0], $rows[0]], 'mapping_form_complete' => '1', 'mapping_row_count' => '2', 'text_catalog_action' => 'selected']);
textPageCheck(str_contains($html, 'different B destination') && count(TextPageRest::$requests) === $requests, 'Duplicate destination is rejected before queuing.');
Session::$update = false;
$html = textPageRender($base + ['text_catalog_action' => 'native']);
textPageCheck(str_contains($html, 'Access denied') && count(TextPageRest::$requests) === $requests, 'Config READ does not permit discovery mutation.');
Session::$read = false;
try { textPageRender(); throw new LogicException('Missing READ check.'); }
catch (RuntimeException $error) { textPageCheck($error->getMessage() === 'Access denied.', 'Page denies missing config READ.'); }

echo "Text Both page: $checks checks PASS (local page/ACL doubles; native GLPI CSRF and backend validation remain runtime gates).\n";
