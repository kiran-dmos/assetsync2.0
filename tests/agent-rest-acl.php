<?php
declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent {
    final class RestPeer {
        public static function settings(): array {
            return ['connection_id' => 'current', 'registration' => 'registration', 'generation' => 'generation',
                'installation' => 'installation', 'agent_peer_user' => 1, 'agent_peer_profile' => 2];
        }
        public static function binding(array $settings): string { return 'current-binding'; }
    }
    final class Settings {
        public static function entities(int $user, int $profile): array { return [2 => 'Exact entity']; }
    }
}
namespace Glpi\DBAL {
    final class QueryExpression {
        public function __construct(public string $expression) {}
    }
}
namespace {
    class CommonDBTM {}
    require __DIR__ . '/../assetsync20agent/src/RestSettings.php';
    require __DIR__ . '/../assetsync20agent/src/RestWork.php';

    function permits(array $criteria, array $row): bool
    {
        foreach ($criteria as $key => $value) {
            if ($value instanceof \Glpi\DBAL\QueryExpression) { continue; }
            if ($key === 'AND' || is_int($key)) {
                if (!permits($value, $row)) { return false; }
            } elseif (is_array($value) && ($value[0] ?? null) === 'LIKE') {
                if ($value[1] !== '%') { throw new LogicException('Unexpected search pattern'); }
            } elseif (is_array($value) ? !in_array($row[$key], $value, true) : $row[$key] !== $value) {
                return false;
            }
        }
        return true;
    }

    foreach ([\GlpiPlugin\Assetsync20agent\RestSettings::class, \GlpiPlugin\Assetsync20agent\RestWork::class] as $resource) {
        $scope = $resource::getSystemSQLCriteria('records');
        $row = ['records.connection_id' => 'current', 'records.registration' => 'registration',
            'records.generation' => 'generation', 'records.installation' => 'installation',
            'records.binding' => 'current-binding', 'records.entities_id' => 2];
        if (!permits($scope, $row)) { throw new RuntimeException('Current binding unexpectedly hidden'); }
        // GLPI 11.0.7 assigns caller searchText predicates after the resource criteria.
        foreach (array_keys($row) as $key) { $scope[$key] = ['LIKE', '%']; }
        foreach ($row as $key => $value) {
            $other = array_replace($row, [$key => is_int($value) ? 3 : 'other']);
            if (permits($scope, $other)) { throw new RuntimeException('Caller filter replaced ACL: ' . $key); }
        }
    }
    $_GET = ['searchText' => ['batch_id' => str_repeat('a', 32)]];
    $scope = \GlpiPlugin\Assetsync20agent\RestWork::getSystemSQLCriteria('records');
    if (($scope['AND']['records.batch_id'] ?? '') !== str_repeat('a', 32) || ($scope['AND']['records.compiler'] ?? null) !== 8) {
        throw new RuntimeException('Batch lookup lacks mandatory exact predicates');
    }
    $row += ['records.batch_id' => str_repeat('a', 32), 'records.compiler' => 8];
    $scope['records.batch_id'] = ['LIKE', '%']; $scope['records.compiler'] = ['LIKE', '%'];
    if (permits($scope, array_replace($row, ['records.batch_id' => str_repeat('b', 32)]))
        || permits($scope, array_replace($row, ['records.compiler' => 7]))) { throw new RuntimeException('Caller wildcard replaced exact batch/compiler predicates'); }
    foreach (['%a%', '^' . str_repeat('a', 32) . '$', ['invalid']] as $bad) {
        $_GET['searchText']['batch_id'] = $bad;
        try { \GlpiPlugin\Assetsync20agent\RestWork::getSystemSQLCriteria('records'); throw new LogicException('Invalid batch query accepted'); }
        catch (RuntimeException) {}
    }
    $_GET = [];
    echo "REST collection ACL passed: searchText cannot replace binding or entity predicates on either resource.\n";
}
