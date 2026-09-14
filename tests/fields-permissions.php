<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use GlpiPlugin\Assetsync20\GlpiBConnection;

$guard = new ReflectionMethod(GlpiBConnection::class, 'assertCustomContainerAccess');
$responses = [
    'getActiveProfile' => ['active_profile' => ['id' => 4, 'computer' => 3]],
    'PluginFieldsContainer/1' => ['id' => 1, 'is_active' => 1, 'itemtypes' => '["Computer"]', 'entities_id' => 0, 'is_recursive' => 0],
    'PluginFieldsContainer/1/PluginFieldsProfile?range=0-999' => [['profiles_id' => 4, 'plugin_fields_containers_id' => 1, 'right' => '4']],
    'Computer/2' => ['id' => 2, 'entities_id' => 0],
];

function verifyAccess(array $responses, bool $writing, ?string $error = null): void
{
    global $guard;
    try {
        $guard->invoke(null, 'Computer', 2, 1, $writing, static function (string $endpoint) use ($responses): array {
            if (!array_key_exists($endpoint, $responses)) {
                throw new RuntimeException('Permission metadata unavailable: ' . $endpoint);
            }
            return $responses[$endpoint];
        });
    } catch (RuntimeException $exception) {
        if ($error !== null && str_contains($exception->getMessage(), $error)) {
            return;
        }
        throw $exception;
    }
    if ($error !== null) {
        throw new RuntimeException('Access unexpectedly allowed; expected ' . $error);
    }
}

verifyAccess($responses, true);
verifyAccess($responses, false);
$denied = $responses;
$denied['PluginFieldsContainer/1/PluginFieldsProfile?range=0-999'][0]['right'] = '1';
verifyAccess($denied, false);
verifyAccess($denied, true, 'container permission denied');
$denied['PluginFieldsContainer/1/PluginFieldsProfile?range=0-999'][0]['right'] = '0';
verifyAccess($denied, false, 'container permission denied');
verifyAccess($denied, true, 'container permission denied');
$denied = $responses;
$denied['PluginFieldsContainer/1/PluginFieldsProfile?range=0-999'] = [];
verifyAccess($denied, true, 'container permission denied');
$denied['PluginFieldsContainer/1/PluginFieldsProfile?range=0-999'] = [['profiles_id' => 5, 'plugin_fields_containers_id' => 1, 'right' => 4]];
verifyAccess($denied, true, 'container permission denied');
$denied['PluginFieldsContainer/1/PluginFieldsProfile?range=0-999'] = [['profiles_id' => 4, 'plugin_fields_containers_id' => 9, 'right' => 4]];
verifyAccess($denied, true, 'container permission denied');
$denied = $responses;
$denied['getActiveProfile'] = [];
verifyAccess($denied, true, 'active profile');
$denied['getActiveProfile'] = ['active_profile' => ['id' => 4, 'computer' => 1]];
verifyAccess($denied, true, 'parent asset update');
$denied = $responses;
$denied['Computer/2']['entities_id'] = 10;
verifyAccess($denied, true, 'outside the container scope');
$denied['PluginFieldsContainer/1']['is_recursive'] = 1;
$denied['Entity/10'] = ['id' => 10, 'entities_id' => 0];
verifyAccess($denied, true);
unset($denied['Entity/10']);
verifyAccess($denied, true, 'Permission metadata unavailable');
$denied['Entity/10'] = ['id' => 10, 'entities_id' => 10];
verifyAccess($denied, true, 'outside the container scope');
foreach (['is_active' => 0, 'itemtypes' => '["Monitor"]', 'id' => 2] as $field => $value) {
    $denied = $responses;
    $denied['PluginFieldsContainer/1'][$field] = $value;
    verifyAccess($denied, true, 'container is unavailable');
}
foreach (['PluginFieldsContainer/1', 'PluginFieldsContainer/1/PluginFieldsProfile?range=0-999', 'Computer/2'] as $endpoint) {
    $denied = $responses;
    unset($denied[$endpoint]);
    verifyAccess($denied, true, 'Permission metadata unavailable');
}
echo "Fields-plugin permission tests passed.\n";
