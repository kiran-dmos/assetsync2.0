<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent {
    final class RestPeer {
        public static function work(array $body): array { return []; }
        public static function binding(array $settings): string { return str_repeat('b', 64); }
    }
    final class RestSettings {
        public static function getTable(): string { return 'rest_settings'; }
        public static function decode(string $body, string $hash): array { return ['version' => 1, 'target_entity' => 2]; }
        public static function binding(array $settings, int $version): string { return RestPeer::binding($settings); }
        public static function authorize(array $body): array { return []; }
    }
    final class TaskJournal { public const TABLE = 'tasks'; }
    final class Outbox {
        public static int $reads = 0;
        public static bool $commitBetweenReads = true;
        public static ?int $owner = null;
        public static function one(string $sql): array {
            if (str_contains($sql, 'rest_settings')) { return ['hash' => str_repeat('c', 64), 'binding' => str_repeat('b', 64), 'body' => '{}']; }
            if (str_contains($sql, 'IS_USED_LOCK')) { return ['owner' => self::$owner]; }
            if (str_contains($sql, '`tasks`')) {
                self::$reads++;
                $terminal = self::$commitBetweenReads && self::$reads > 1;
                return ['state' => $terminal ? 'verified' : 'executing', 'result' => $terminal ? '{"outcome":"verified"}' : '{}',
                    'hash' => str_repeat('d', 64), 'body' => '{"version":7}'];
            }
            throw new \LogicException('Unexpected observation SQL');
        }
        public static function sql(string $sql): void { throw new \LogicException('GET attempted a write'); }
    }
}
namespace {
    class CommonDBTM {
        public array $fields = [];
        public function getFromDB($id) { global $record; $this->fields = $record; return true; }
    }
    class ObservationDB { public string $dbdefault = 'owned'; public function quote(string $value): string { return "'" . $value . "'"; } }
    require __DIR__ . '/../assetsync20agent/src/TaskExecutor.php';
    require __DIR__ . '/../assetsync20agent/src/RestWork.php';
    $DB = new ObservationDB();
    $body = ['version' => 7, 'id' => str_repeat('a', 32), 'installation' => str_repeat('a', 32), 'registration' => str_repeat('a', 32),
        'generation' => str_repeat('a', 32), 'connection_id' => 'owned', 'settings_id' => str_repeat('a', 32), 'settings_hash' => str_repeat('c', 64),
        'pair_id' => str_repeat('a', 32), 'itemtype' => 'Computer', 'items_id' => 1, 'entity_id' => 2, 'identity' => ['serial' => 'S'],
        'name' => 'Name', 'source_hash' => str_repeat('c', 64), 'expires' => time() + 300];
    $record = ['body' => json_encode($body), 'hash' => \GlpiPlugin\Assetsync20agent\TaskExecutor::hash($body),
        'binding' => str_repeat('b', 64), 'task_body' => '{"version":7}', 'task_hash' => str_repeat('d', 64)];
    $work = new \GlpiPlugin\Assetsync20agent\RestWork();
    $work->getFromDB(1);
    if ($work->fields['state'] !== 'verified' || \GlpiPlugin\Assetsync20agent\Outbox::$reads !== 2) {
        throw new RuntimeException('Commit between initial journal read and owner check was mislabeled uncertain');
    }
    \GlpiPlugin\Assetsync20agent\Outbox::$reads = 0;
    \GlpiPlugin\Assetsync20agent\Outbox::$commitBetweenReads = false;
    $work->getFromDB(1);
    if ($work->fields['state'] !== 'uncertain' || \GlpiPlugin\Assetsync20agent\Outbox::$reads !== 2) { throw new RuntimeException('Orphaned intent not read twice'); }
    \GlpiPlugin\Assetsync20agent\Outbox::$reads = 0;
    \GlpiPlugin\Assetsync20agent\Outbox::$owner = 123;
    $work->getFromDB(1);
    if ($work->fields['state'] !== 'executing' || \GlpiPlugin\Assetsync20agent\Outbox::$reads !== 1) { throw new RuntimeException('Live executor mislabeled uncertain'); }
    echo "REST observation race passed: intervening terminal commit wins; orphaned/live intent distinguished without writes.\n";
}
