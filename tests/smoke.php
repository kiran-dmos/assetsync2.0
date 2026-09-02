<?php

declare(strict_types=1);

define('GLPI_VERSION', '11.0.7');

class Config
{
    public static array $values = [];

    public static function getConfigurationValues($context, array $names = []): array
    {
        $values = self::$values[$context] ?? [];

        if ($names === []) {
            return $values;
        }

        return array_intersect_key($values, array_flip($names));
    }

    public static function setConfigurationValues($context, array $values = []): void
    {
        self::$values[$context] = array_merge(self::$values[$context] ?? [], $values);
    }

    public static function deleteConfigurationValues($context, array $values = []): void
    {
        foreach ($values as $name) {
            unset(self::$values[$context][$name]);
        }
    }
}

class GLPIKey
{
    public function encrypt(string $string, ?string $key = null): string
    {
        return 'encrypted:' . base64_encode($string);
    }

    public function decrypt(?string $string, ?string $key = null): ?string
    {
        if ($string === null || !str_starts_with($string, 'encrypted:')) {
            return $string;
        }

        $decoded = base64_decode(substr($string, strlen('encrypted:')), true);

        return is_string($decoded) ? $decoded : $string;
    }
}

require_once dirname(__DIR__) . '/setup.php';
require_once dirname(__DIR__) . '/hook.php';

$metadata = plugin_version_assetsync20();

$expectations = [
    'id' => 'assetsync20',
    'name' => 'AssetSync2.0',
    'version' => '0.1.3',
];

foreach ($expectations as $key => $expected) {
    if (($metadata[$key] ?? null) !== $expected) {
        throw new RuntimeException(sprintf(
            'Expected metadata %s to be %s, got %s.',
            $key,
            $expected,
            var_export($metadata[$key] ?? null, true)
        ));
    }
}

if (!plugin_assetsync20_check_prerequisites()) {
    throw new RuntimeException('Prerequisite check failed.');
}

if (!plugin_assetsync20_check_config()) {
    throw new RuntimeException('Configuration check failed.');
}

if (!plugin_assetsync20_install()) {
    throw new RuntimeException('Install hook failed.');
}

$menu = \GlpiPlugin\Assetsync20\Menu::getMenuContent();

if (($menu['title'] ?? null) !== 'AssetSync2.0') {
    throw new RuntimeException('Menu title is incorrect.');
}

if (($menu['icon'] ?? null) !== 'ti ti-refresh') {
    throw new RuntimeException('Menu icon is incorrect.');
}

if (($menu['links']['fieldmapping'] ?? null) !== '/plugins/assetsync20/front/fieldmapping.php') {
    throw new RuntimeException('Field Mapping menu link is incorrect.');
}

$connections = \GlpiPlugin\Assetsync20\GlpiBConnection::loadAll();

if ($connections !== []) {
    throw new RuntimeException('GLPI B connections should be empty by default.');
}

$connection = \GlpiPlugin\Assetsync20\GlpiBConnection::load();
$test = \GlpiPlugin\Assetsync20\GlpiBConnection::test($connection);

if ($test['success'] !== false || $test['message'] !== 'Base URL, app token, and user token are required.') {
    throw new RuntimeException('Empty GLPI B connection test result is incorrect.');
}

$connectionFromInput = \GlpiPlugin\Assetsync20\GlpiBConnection::fromInput([
    'name' => ' GLPI B ',
    'base_url' => ' https://glpi-b.example.com/apirest.php/ ',
    'active' => '1',
]);

if ($connectionFromInput['name'] !== 'GLPI B') {
    throw new RuntimeException('GLPI B connection name was not normalized.');
}

if ($connectionFromInput['base_url'] !== 'https://glpi-b.example.com') {
    throw new RuntimeException('GLPI B base URL was not normalized.');
}

if ($connectionFromInput['active'] !== true) {
    throw new RuntimeException('GLPI B active flag was not normalized.');
}

\GlpiPlugin\Assetsync20\GlpiBConnection::save([
    'id' => 'production',
    'name' => 'Production GLPI B',
    'base_url' => 'https://glpi-b.example.com/apirest.php',
    'app_token' => 'app-secret',
    'user_token' => 'user-secret',
    'active' => '1',
]);

$savedRawValues = Config::getConfigurationValues('plugin:assetsync20');
$savedRawConnections = json_decode((string) ($savedRawValues['glpib_connections'] ?? ''), true);

if (!is_array($savedRawConnections) || count($savedRawConnections) !== 1) {
    throw new RuntimeException('Saved GLPI B connections should be stored as a JSON list.');
}

if (($savedRawConnections[0]['app_token'] ?? '') === 'app-secret') {
    throw new RuntimeException('Saved app token should not be stored in plain text.');
}

$savedConnection = \GlpiPlugin\Assetsync20\GlpiBConnection::find('production');

if ($savedConnection === null) {
    throw new RuntimeException('Saved GLPI B connection could not be found.');
}

if ($savedConnection['base_url'] !== 'https://glpi-b.example.com') {
    throw new RuntimeException('Saved GLPI B base URL was not normalized.');
}

if ($savedConnection['app_token'] !== 'app-secret' || $savedConnection['user_token'] !== 'user-secret') {
    throw new RuntimeException('Saved GLPI B tokens could not be loaded.');
}

\GlpiPlugin\Assetsync20\FieldMapping::save('production', 'Computer', [
    'name' => 'glpi_b',
    'serial' => 'both',
    'invalid_field' => 'glpi_a',
    'comment' => 'invalid_source',
]);

$savedMappings = \GlpiPlugin\Assetsync20\FieldMapping::load('production', 'Computer');

if ($savedMappings !== ['name' => 'glpi_b', 'serial' => 'both']) {
    throw new RuntimeException('Field mappings should save valid source selections only.');
}

$assetTypes = \GlpiPlugin\Assetsync20\FieldMapping::assetTypes();

if (($assetTypes['NetworkEquipment'] ?? null) !== 'Network Equipment') {
    throw new RuntimeException('Network equipment asset type label is incorrect.');
}

$sourceOptions = \GlpiPlugin\Assetsync20\FieldMapping::sourceOptions();

if (($sourceOptions['both'] ?? null) !== 'Both') {
    throw new RuntimeException('Both source option is missing.');
}

\GlpiPlugin\Assetsync20\GlpiBConnection::save([
    'id' => 'production',
    'name' => 'Renamed GLPI B',
    'base_url' => 'https://glpi-b.example.com',
    'active' => '1',
]);

$savedConnection = \GlpiPlugin\Assetsync20\GlpiBConnection::find('production');

if ($savedConnection === null) {
    throw new RuntimeException('Edited GLPI B connection could not be found.');
}

if ($savedConnection['name'] !== 'Renamed GLPI B') {
    throw new RuntimeException('Saved GLPI B connection name was not updated.');
}

if ($savedConnection['app_token'] !== 'app-secret' || $savedConnection['user_token'] !== 'user-secret') {
    throw new RuntimeException('Blank token fields should keep saved GLPI B tokens.');
}

$savedConnections = \GlpiPlugin\Assetsync20\GlpiBConnection::loadAll();

if (count($savedConnections) !== 1) {
    throw new RuntimeException('Editing an existing GLPI B connection should not create a duplicate.');
}

\GlpiPlugin\Assetsync20\GlpiBConnection::save([
    'id' => 'staging',
    'name' => 'Staging GLPI B',
    'base_url' => 'https://staging-glpi-b.example.com',
    'app_token' => 'staging-app-secret',
    'user_token' => 'staging-user-secret',
    'active' => '1',
]);

$savedConnections = \GlpiPlugin\Assetsync20\GlpiBConnection::loadAll();

if (count($savedConnections) !== 2) {
    throw new RuntimeException('Multiple GLPI B connections should be saved.');
}

$activeCount = 0;
foreach ($savedConnections as $savedConnection) {
    if ($savedConnection['active']) {
        $activeCount++;
    }
}

if ($activeCount !== 2) {
    throw new RuntimeException('Multiple GLPI B connections should be allowed to be active.');
}

\GlpiPlugin\Assetsync20\GlpiBConnection::delete('staging');

if (\GlpiPlugin\Assetsync20\GlpiBConnection::find('staging') !== null) {
    throw new RuntimeException('Deleted GLPI B connection should not be loaded.');
}

Config::$values = [
    'plugin:assetsync20' => [
        'glpib_name' => 'Legacy GLPI B',
        'glpib_base_url' => 'https://legacy-glpi-b.example.com/apirest.php',
        'glpib_app_token' => (new GLPIKey())->encrypt('legacy-app-secret'),
        'glpib_user_token' => (new GLPIKey())->encrypt('legacy-user-secret'),
        'glpib_active' => '1',
    ],
];

$legacyConnections = \GlpiPlugin\Assetsync20\GlpiBConnection::loadAll();

if (count($legacyConnections) !== 1) {
    throw new RuntimeException('Legacy GLPI B connection should migrate into the new connection list.');
}

if (
    $legacyConnections[0]['name'] !== 'Legacy GLPI B'
    || $legacyConnections[0]['base_url'] !== 'https://legacy-glpi-b.example.com'
    || $legacyConnections[0]['app_token'] !== 'legacy-app-secret'
    || $legacyConnections[0]['user_token'] !== 'legacy-user-secret'
    || $legacyConnections[0]['active'] !== true
) {
    throw new RuntimeException('Migrated legacy GLPI B connection values are incorrect.');
}

if (!plugin_assetsync20_uninstall()) {
    throw new RuntimeException('Uninstall hook failed.');
}

$remainingValues = Config::getConfigurationValues('plugin:assetsync20');

foreach (['glpib_connections', 'field_mappings', 'glpib_name', 'glpib_base_url', 'glpib_app_token', 'glpib_user_token', 'glpib_active'] as $deletedKey) {
    if (array_key_exists($deletedKey, $remainingValues)) {
        throw new RuntimeException('Uninstall should delete ' . $deletedKey . '.');
    }
}

echo "assetsync2.0 smoke test passed.\n";
