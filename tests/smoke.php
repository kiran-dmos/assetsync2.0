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
    'version' => '0.1.1',
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

if (!plugin_assetsync20_uninstall()) {
    throw new RuntimeException('Uninstall hook failed.');
}

$menu = \GlpiPlugin\Assetsync20\Menu::getMenuContent();

if (($menu['title'] ?? null) !== 'AssetSync2.0') {
    throw new RuntimeException('Menu title is incorrect.');
}

if (($menu['icon'] ?? null) !== 'ti ti-refresh') {
    throw new RuntimeException('Menu icon is incorrect.');
}

$connection = \GlpiPlugin\Assetsync20\GlpiBConnection::load();

if ($connection['active'] !== false) {
    throw new RuntimeException('GLPI B connection should be inactive by default.');
}

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
    'name' => 'Production GLPI B',
    'base_url' => 'https://glpi-b.example.com/apirest.php',
    'app_token' => 'app-secret',
    'user_token' => 'user-secret',
    'active' => '1',
]);

$savedRawValues = Config::getConfigurationValues('plugin:assetsync20');

if (($savedRawValues['glpib_app_token'] ?? '') === 'app-secret') {
    throw new RuntimeException('Saved app token should not be stored in plain text.');
}

$savedConnection = \GlpiPlugin\Assetsync20\GlpiBConnection::load();

if ($savedConnection['base_url'] !== 'https://glpi-b.example.com') {
    throw new RuntimeException('Saved GLPI B base URL was not normalized.');
}

if ($savedConnection['app_token'] !== 'app-secret' || $savedConnection['user_token'] !== 'user-secret') {
    throw new RuntimeException('Saved GLPI B tokens could not be loaded.');
}

\GlpiPlugin\Assetsync20\GlpiBConnection::save([
    'name' => 'Renamed GLPI B',
    'base_url' => 'https://glpi-b.example.com',
    'active' => '1',
]);

$savedConnection = \GlpiPlugin\Assetsync20\GlpiBConnection::load();

if ($savedConnection['name'] !== 'Renamed GLPI B') {
    throw new RuntimeException('Saved GLPI B connection name was not updated.');
}

if ($savedConnection['app_token'] !== 'app-secret' || $savedConnection['user_token'] !== 'user-secret') {
    throw new RuntimeException('Blank token fields should keep saved GLPI B tokens.');
}

echo "assetsync2.0 smoke test passed.\n";
