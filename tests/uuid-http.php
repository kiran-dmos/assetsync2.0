<?php

declare(strict_types=1);

require __DIR__ . '/remote-catalog-pagination.php';
require __DIR__ . '/uuid-service.php';

use GlpiPlugin\Assetsync20\GlpiBConnection;
use GlpiPlugin\Assetsync20\AssetUuidService;
use GlpiPlugin\Assetsync20\RemoteRequestFailure;

$startChecks = $checks;
$connection = ['id' => 'catalog', 'base_url' => 'https://private.invalid', 'app_token' => 'private-app', 'user_token' => 'private-user', 'active' => true];
$timezone = catalogStep('getFullSession', ['session' => ['glpitimezone' => 'UTC']]);
$target = '12345678-1234-4234-8234-123456789012';
function uuidHttp(array $connection, string $type, bool $writing = false, ?string $old = null, ?string $new = null, bool $history = true): array
{
    try {
        return GlpiBConnection::uuidSnapshot($connection, $type, 10, 5, $writing, $old, $new, $history);
    } catch (RemoteRequestFailure $error) {
        return $error->result;
    } catch (RuntimeException $error) {
        return ['success' => false, 'message' => $error->getMessage()];
    }
}
function uuidLockStep(string $type, array $rows = [], array $headers = [], bool $global = false): array
{
    return catalogStep($global ? 'Lockedfield' : $type . '/10/Lockedfield', $rows, 200, $headers) + ['query' => [
        'range' => '0-99', 'sort' => 'id', 'order' => 'ASC', 'get_hateoas' => '0',
        'searchText' => $global ? ['itemtype' => '^' . $type . '$', 'field' => '^uuid$', 'is_global' => '^1$'] : ['field' => '^uuid$'],
    ]];
}

foreach (['Computer' => 'computer', 'Monitor' => 'monitor', 'Printer' => 'printer', 'Phone' => 'phone', 'NetworkEquipment' => 'networking', 'Peripheral' => 'peripheral'] as $type => $right) {
    $item = ['id' => 10, 'entities_id' => 5, 'uuid' => '', 'date_mod' => '2026-10-01 00:00:00', 'is_deleted' => 0, 'is_template' => 0];
    $profile = catalogStep('getActiveProfile', ['active_profile' => ['id' => 1, $right => 3, 'locked_field' => 2]]);
    $persisted = array_replace($item, ['uuid' => $target, 'date_mod' => '2026-10-07 00:00:00']);
    catalogStart([$init, $timezone, catalogStep($type . '/10', $item), $profile,
        uuidLockStep($type), uuidLockStep($type, [], [], true),
        catalogStep($type . '/10') + ['method' => 'PUT', 'input' => ['id' => 10, 'uuid' => $target]],
        catalogStep($type . '/10', $persisted), catalogStep($type . '/10/Log'), $kill]);
    $result = uuidHttp($connection, $type, true, '', $target);
    catalogCheck($result['success'] && $result['item']['uuid'] === $target && $result['date_mod_timezone'] === 'UTC', 'All six native UUID writes require proper rights, raw readback, no search-option dependency: ' . $type);
    catalogFinish(10);

    foreach ([$right => 1, 'locked_field' => 1] as $denied => $value) {
        $rights = ['id' => 1, $right => 3, 'locked_field' => 2];
        $rights[$denied] = $value;
        catalogStart([$init, $timezone, catalogStep($type . '/10', $item), catalogStep('getActiveProfile', ['active_profile' => $rights]), $kill]);
        $result = uuidHttp($connection, $type, true, '', $target);
        catalogCheck(!$result['success'] && $result['outcome'] === 'permission', 'Denied parent or locked-field right must not start lock discovery or PUT.');
        catalogFinish(5);
    }
}

$type = 'Computer';
$profile = catalogStep('getActiveProfile', ['active_profile' => ['id' => 1, 'computer' => 3, 'locked_field' => 2]]);
$item = ['id' => 10, 'entities_id' => 5, 'uuid' => '', 'date_mod' => '2026-10-01 00:00:00', 'is_deleted' => 0, 'is_template' => 0];
$lock = ['id' => 1, 'itemtype' => 'Computer', 'items_id' => 10, 'field' => 'uuid', 'is_global' => 0];
foreach ([false, true] as $global) {
    $row = $global ? array_replace($lock, ['items_id' => 0, 'is_global' => 1]) : $lock;
    catalogStart([$init, catalogStep('Computer/10', $item), $profile,
        uuidLockStep($type, $global ? [] : [$row], $global ? [] : ['Content-Range: 0-0/1']),
        uuidLockStep($type, $global ? [$row] : [], $global ? ['Content-Range: 0-0/1'] : [], true), $kill]);
    $result = uuidHttp($connection, $type, true, null, null, false);
    catalogCheck(!$result['success'] && $result['outcome'] === 'locked', 'Existing asset or global UUID lock blocks a native write.');
    catalogFinish(6);
}
foreach (['itemtype' => 'Printer', 'field' => 'name', 'items_id' => '10garbage', 'is_global' => 2] as $field => $bad) {
    catalogStart([$init, catalogStep('Computer/10', $item), $profile,
        uuidLockStep($type, [array_replace($lock, [$field => $bad])], ['Content-Range: 0-0/1']), uuidLockStep($type, [], [], true), $kill]);
    $result = uuidHttp($connection, $type, true, null, null, false);
    catalogCheck(!$result['success'] && $result['outcome'] === 'unknown', 'Malformed lock identity cannot mean unlocked: ' . $field);
    catalogFinish(6);
}
foreach ([[], ['Content-Range: 0-1/2'], ['Content-Range: 0-0/1001']] as $headers) {
    catalogStart([$init, catalogStep('Computer/10', $item), $profile, uuidLockStep($type, [$lock], $headers), $kill]);
    $result = uuidHttp($connection, $type, true, null, null, false);
    catalogCheck(!$result['success'], 'Missing, incoherent or excessive lock range fails closed.');
    catalogFinish(5);
}
$locks = [];
for ($id = 1; $id <= 100; $id++) { $locks[] = array_replace($lock, ['id' => $id]); }
catalogStart([$init, catalogStep('Computer/10', $item), $profile,
    uuidLockStep($type, $locks, ['Content-Range: 0-99/101']),
    catalogStep('Computer/10/Lockedfield', [array_replace($lock, ['id' => 101])], 206, ['Content-Range: 100-100/101']) + ['query' => ['range' => '100-199', 'searchText' => ['field' => '^uuid$']]],
    uuidLockStep($type, [], [], true), $kill]);
catalogCheck(uuidHttp($connection, $type, true, null, null, false)['outcome'] === 'locked', 'Complete lock discovery includes the last coherent page.');
catalogFinish(7);

catalogStart([$init, $timezone, catalogStep('Computer/10', $item), $profile, uuidLockStep($type), uuidLockStep($type, [], [], true),
    catalogStep('Computer/10') + ['method' => 'PUT', 'input' => ['id' => 10, 'uuid' => $target]], catalogStep('Computer/10', $item), $kill]);
$result = uuidHttp($connection, $type, true, '', $target);
catalogCheck(!$result['success'] && $result['outcome'] === 'unverified', 'Successful PUT ignored by the model is not verified success.');
catalogFinish(9);

catalogStart([]);
catalogCheck(!uuidHttp(array_replace($connection, ['active' => false]), $type)['success'], 'Disabled dedicated UUID client performs zero HTTP.');
catalogCheck(CatalogCurl::$executions === 0 && CatalogCurl::$steps === [], 'Disabled connection has no transport calls.');
GlpiBConnection::finishHttpMetrics();
catalogStart([$init, $timezone, catalogStep('Computer/10', array_replace($item, ['entities_id' => 6])), $kill]);
catalogCheck(!uuidHttp($connection, $type, true)['success'], 'Wrong parent entity prevents global lock access and mutation.');
catalogFinish(4);
echo 'UUID HTTP tests passed (' . ($checks - $startChecks) . " checks).\n";
