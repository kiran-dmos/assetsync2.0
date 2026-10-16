<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent {
    final class ServiceContext { public static function computer(array $settings): object { return new \Computer(); } }
    final class TargetScope { public static function model(string $type): object { return new \Computer(); } }
    final class Snapshot { public static function containers(string $type): array { throw new \LogicException('Unrelated Fields loaded'); } }
    final class Outbox {
        public static array $queries = [];
        public static function one(string $sql): array {
            self::$queries[] = $sql;
            if (str_contains($sql, 'ENGINE')) { return ['ENGINE' => 'InnoDB']; }
            return ['id' => 1, 'entities_id' => 2, 'name' => 'Native Name', 'comment' => 'PRIVATE UNMAPPED',
                'serial' => 'SERIAL', 'otherserial' => 'OTHER', 'date_mod' => '2026-10-16 00:00:00', 'history_id' => 12];
        }
        public static function sql(string $sql): void { self::$queries[] = $sql; }
    }
}
namespace Glpi\Search {
    final class SearchOption {
        public static function getOptionsForItemtype(string $type): array { throw new \LogicException('Plugin metadata loaded'); }
        public static function clearSearchOptionCache(string $type): void {}
    }
}
namespace {
    class Computer {
        public static function getTable(): string { return 'glpi_computers'; }
        public function rawSearchOptions(): array {
            return [['id' => 1, 'table' => 'glpi_computers', 'field' => 'name', 'name' => 'Name'],
                ['id' => 16, 'table' => 'glpi_computers', 'field' => 'comment', 'name' => 'Comments']];
        }
    }
    class Plugin { public static function isPluginActive(string $name): bool { return true; } }
    class NameDB { public function quote(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; } }
    require __DIR__ . '/../assetsync20agent/src/TaskExecutor.php';
    require __DIR__ . '/../assetsync20agent/src/RestPeer.php';
    $DB = new NameDB();
    $settings = ['itemtype' => 'Computer', 'items_id' => 1, 'entity_id' => 2, 'pair_id' => str_repeat('a', 32)];
    $snapshot = \GlpiPlugin\Assetsync20agent\TaskExecutor::snapshot($settings, 7);
    if (array_keys($snapshot['fields']) !== ['name'] || array_keys($snapshot['native_values']) !== ['name']
        || str_contains(json_encode($snapshot), 'PRIVATE UNMAPPED')) { throw new RuntimeException('Unmapped data exported'); }
    $lock = new ReflectionMethod(\GlpiPlugin\Assetsync20agent\TaskExecutor::class, 'lockRows');
    $lock->invoke(null, $settings, true);
    foreach (\GlpiPlugin\Assetsync20agent\Outbox::$queries as $sql) {
        if (str_contains($sql, 'plugin_fields')) { throw new RuntimeException('Unrelated Fields storage touched'); }
    }
    $binding = ['mode' => 'agent', 'installation' => 'i', 'registration' => 'r', 'generation' => 'g', 'connection_id' => 'c',
        'service_user' => '1', 'service_profile' => '2', 'service_name' => 'writer', 'agent_peer_user' => '3',
        'agent_peer_api_client' => '4', 'agent_peer_profile' => '5', 'agent_peer_entity' => '0'];
    $hash = \GlpiPlugin\Assetsync20agent\RestPeer::binding($binding);
    if ($hash !== \GlpiPlugin\Assetsync20agent\RestPeer::binding($binding + ['service_password' => 'rotated', 'agent_api_url' => 'different',
        'agent_app_token' => 'rotated', 'agent_user_token' => 'rotated'])) { throw new RuntimeException('Unrelated secret rotation changed work binding'); }
    if ($hash === \GlpiPlugin\Assetsync20agent\RestPeer::binding(array_replace($binding, ['agent_peer_user' => '6']))) { throw new RuntimeException('Peer identity not pinned'); }
    echo "REST Name component passed: active unrelated Fields skipped, Name-only export/parent lock, stable binding.\n";
}
