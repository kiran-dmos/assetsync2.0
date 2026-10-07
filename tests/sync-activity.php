<?php

declare(strict_types=1);

namespace Glpi\DBAL {
    final class QuerySubQuery
    {
        public function __construct(public array $query) {}
    }
}

namespace {
    require_once __DIR__ . '/query-expression.php';
    require_once __DIR__ . '/../src/autoload.php';

    use GlpiPlugin\Assetsync20\AssetSyncLink;
    use GlpiPlugin\Assetsync20\AssetSyncQueue;
    use GlpiPlugin\Assetsync20\SyncActivity;
    use Glpi\DBAL\QueryExpression;
    use Glpi\DBAL\QuerySubQuery;

    define('READ', 1);
    define('READ_ASSIGNED', 1024);
    define('READ_OWNED', 2048);

    function check(bool $condition, string $message): void
    {
        if (!$condition) { throw new \RuntimeException($message); }
    }

    class Session
    {
        public static array $entities = [0];
        public static array $rights = ['config' => READ, 'computer' => READ, 'monitor' => READ, 'printer' => READ, 'phone' => READ, 'networking' => READ, 'peripheral' => READ];
        public static string $interface = 'central';
        public static int $user = 7;
        public static bool $valid = true;
        public static bool $cron = false;
        public static bool $disabled = false;
        public static function checkCentralAccess(): void { check(self::$valid && self::$interface === 'central', 'Denied central access.'); }
        public static function getCurrentInterface(): string { return self::$interface; }
        public static function getLoginUserID(): int { return self::$user; }
        public static function getActiveEntities(): array { return self::$entities; }
        public static function isCron(): bool { return self::$cron; }
        public static function isRightChecksDisabled(): bool { return self::$disabled; }
        public static function haveRight(string $name, int $right): bool { return ((self::$rights[$name] ?? 0) & $right) === $right; }
    }

    abstract class ActivityAsset
    {
        public static string $rightname;
        public static function canView(): bool
        {
            return Session::haveRight(static::$rightname, READ) || Session::haveRight(static::$rightname, READ_ASSIGNED) || Session::haveRight(static::$rightname, READ_OWNED);
        }
        // Mirrors the installed GLPI AssignableItem central criteria, including group types.
        public static function getAssignableVisiblityCriteria(string $table): array
        {
            if (Session::haveRight(static::$rightname, READ)) { return [new QueryExpression('1')]; }
            $or = [];
            foreach ([READ_ASSIGNED => ['users_id_tech', 2], READ_OWNED => ['users_id', 1]] as $right => [$column, $groupType]) {
                if (!Session::haveRight(static::$rightname, $right)) { continue; }
                $or[] = [$table . '.' . $column => Session::$user];
                if (($_SESSION['glpigroups'] ?? []) !== []) {
                    $or[] = [$table . '.id' => new QuerySubQuery(['SELECT' => 'glpi_groups_items.items_id', 'FROM' => 'glpi_groups_items',
                        'WHERE' => ['itemtype' => static::class, 'groups_id' => $_SESSION['glpigroups'], 'type' => $groupType]])];
                }
            }
            return [['OR' => $or]];
        }
    }
    class Computer extends ActivityAsset { public static string $rightname = 'computer'; }
    class Monitor extends ActivityAsset { public static string $rightname = 'monitor'; }
    class Printer extends ActivityAsset { public static string $rightname = 'printer'; }
    class Phone extends ActivityAsset { public static string $rightname = 'phone'; }
    class Peripheral extends ActivityAsset { public static string $rightname = 'peripheral'; }
    class NetworkEquipment extends ActivityAsset { public static string $rightname = 'networking'; }

    class Config
    {
        public static array $values = [];
        public static array $reads = [];
        public static function getConfigurationValues(string $context, array $names): array
        {
            self::$reads[] = [$context, $names];
            check($context === 'plugin:assetsync20' && $names === ['glpib_connections'], 'Unexpected config read.');
            return array_intersect_key(self::$values, array_flip($names));
        }
        public static function setConfigurationValues(...$args): never { throw new \LogicException('Config write attempted.'); }
        public static function deleteConfigurationValues(...$args): never { throw new \LogicException('Config delete attempted.'); }
    }
    class GLPIKey { public function decrypt(...$args): never { throw new \LogicException('Token decryption attempted.'); } }
    class CronTask
    {
        public static int $forced = 0;
        public static function callCron(): void
        {
            if (isset($_SESSION['glpicrontimer']) && time() - $_SESSION['glpicrontimer'] > 300) {
                self::$forced++;
                throw new \LogicException('Live cron attempted.');
            }
            $_SESSION['glpicrontimer'] ??= time();
        }
    }
    class Html
    {
        public static bool $throw = false;
        public static function header(...$args): void
        {
            CronTask::callCron();
            if (self::$throw) { throw new \RuntimeException('Header exception.'); }
        }
        public static function footer(): void {}
        public static function getPrefixedUrl(string $path): string { return '/glpi' . $path; }
    }

    final class ActivityDB
    {
        public array $queue = [];
        public array $assets = [];
        public array $links = [];
        public array $groups = [];
        public array $entities = [0 => 'Root', 2 => 'Branch', 9 => 'Hidden entity'];
        public array $entityParents = [0 => 0, 2 => 0, 9 => 0];
        public array $requests = [];
        public bool $hasRecheck = true;
        public bool $throw = false;
        public function fieldExists(string $table, string $column): bool
        {
            check($table === AssetSyncQueue::TABLE && $column === 'needs_recheck', 'Unexpected schema read.');
            return $this->hasRecheck;
        }
        public function insert(...$args): never { throw new \LogicException('DB insert attempted.'); }
        public function update(...$args): never { throw new \LogicException('DB update attempted.'); }
        public function delete(...$args): never { throw new \LogicException('DB delete attempted.'); }
        public function doQuery(...$args): never { throw new \LogicException('Raw SQL/write attempted.'); }
        public function request(array $query): array
        {
            $this->requests[] = $query;
            if ($this->throw) { throw new \RuntimeException('user_token=SECRET_DATABASE_ERROR'); }
            if ($query['FROM'] === 'glpi_entities') {
                check($query['SELECT'] === ['id', 'entities_id'], 'Unexpected entity hierarchy read.');
                $rows = [];
                foreach ($query['WHERE']['id'] as $id) {
                    if (array_key_exists($id, $this->entityParents)) { $rows[] = ['id' => $id, 'entities_id' => $this->entityParents[$id]]; }
                }
                return $rows;
            }
            check($query['FROM'] === AssetSyncQueue::TABLE, 'Only the local queue may be queried.');
            check($this->hasRecheck || !str_contains(json_encode($query), AssetSyncQueue::TABLE . '.needs_recheck'), 'Legacy query referenced an absent recheck column.');
            $table = array_key_first($query['INNER JOIN']);
            check((isset($query['WHERE'][$table . '.entities_id']) || isset($query['WHERE'][0]['OR']))
                && isset($query['WHERE'][$table . '.is_deleted'], $query['WHERE'][$table . '.is_template'], $query['WHERE'][0]), 'Missing pre-limit permission scope.');
            foreach ($query['SELECT'] as $column) {
                check(!is_string($column) || !str_contains($column, 'last_error'), 'Raw error selected.');
            }
            if (isset($query['LEFT JOIN'][AssetSyncLink::TABLE])) {
                check(count($query['LEFT JOIN'][AssetSyncLink::TABLE]['AND']) === 3, 'Link identity join is incomplete.');
            }
            $joined = [];
            foreach ($this->queue as $job) {
                $asset = $this->assets[$table][$job['items_id']] ?? null;
                if ($asset === null) { continue; }
                $row = [];
                foreach ($job as $column => $value) { $row[AssetSyncQueue::TABLE . '.' . $column] = $value; }
                foreach ($asset as $column => $value) { $row[$table . '.' . $column] = $value; }
                $row['glpi_entities.completename'] = $this->entities[$asset['entities_id']] ?? null;
                $link = $this->links[$job['glpi_b_connection_id'] . ':' . $job['itemtype'] . ':' . $job['items_id']] ?? [];
                foreach (['id', 'remote_items_id', 'route_id', 'status', 'last_sync_at'] as $column) { $row[AssetSyncLink::TABLE . '.' . $column] = $link[$column] ?? null; }
                if ($this->matches($row, $query['WHERE'])) { $joined[] = $row; }
            }
            if (isset($query['GROUPBY'])) {
                $groups = [];
                foreach ($joined as $row) {
                    $key = json_encode(array_map(static fn ($column) => $row[$column], $query['GROUPBY']));
                    $groups[$key][] = $row;
                }
                $result = [];
                foreach ($groups as $rows) {
                    $row = $this->project($rows[0], $query['SELECT']);
                    $row['row_count'] = count($rows);
                    $ids = array_column($rows, AssetSyncQueue::TABLE . '.id');
                    $row['min_id'] = min($ids);
                    $row['max_id'] = max($ids);
                    $result[] = $row;
                }
                return $result;
            }
            if (isset($query['ORDER'])) {
                $ascending = str_ends_with($query['ORDER'], 'ASC');
                usort($joined, static fn ($a, $b) => $ascending ? $a[AssetSyncQueue::TABLE . '.id'] <=> $b[AssetSyncQueue::TABLE . '.id'] : $b[AssetSyncQueue::TABLE . '.id'] <=> $a[AssetSyncQueue::TABLE . '.id']);
            }
            check(($query['LIMIT'] ?? 0) > 0 && $query['LIMIT'] <= SyncActivity::PAGE_SIZE, 'Unbounded row read.');
            return array_map(fn ($row) => $this->project($row, $query['SELECT']), array_slice($joined, 0, $query['LIMIT']));
        }
        private function matches(array $row, array $where): bool
        {
            foreach ($where as $key => $value) {
                if (is_int($key)) {
                    if ($value instanceof QueryExpression) { if ($value->expression !== '1') { return false; } }
                    elseif (!$this->matches($row, $value)) { return false; }
                    continue;
                }
                if ($key === 'OR') {
                    $matched = false;
                    foreach ($value as $k => $v) { if ($this->matches($row, is_int($k) ? $v : [$k => $v])) { $matched = true; } }
                    if (!$matched) { return false; }
                    continue;
                }
                $actual = $row[$key] ?? null;
                if ($value instanceof QuerySubQuery) {
                    $ids = [];
                    foreach ($this->groups as $group) { if ($this->matches($group, $value->query['WHERE'])) { $ids[] = $group['items_id']; } }
                    if (!in_array($actual, $ids, true)) { return false; }
                } elseif (is_array($value)) {
                    $operator = $value[0] ?? null;
                    if ($operator === '<' || $operator === '>') {
                        if ($operator === '<' ? $actual >= $value[1] : $actual <= $value[1]) { return false; }
                    } elseif ($operator === 'LIKE') {
                        $needle = substr($value[1], 1, -1);
                        $needle = str_replace(['\\%', '\\_', '\\\\'], ['%', '_', '\\'], $needle);
                        if (stripos((string) $actual, $needle) === false) { return false; }
                    } elseif (!in_array($actual, $value, true)) { return false; }
                } elseif ((string) $actual !== (string) $value) { return false; }
            }
            return true;
        }
        private function project(array $row, array $select): array
        {
            $result = [];
            foreach ($select as $column) {
                if ($column instanceof QueryExpression) {
                    if ($column->expression === 'NULL') { $result[$column->alias] = null; }
                    if (preg_match('/^`([^`]+)`\.`([^`]+)`$/', $column->expression, $match)) { $result[$column->alias] = $row[$match[1] . '.' . $match[2]]; }
                    continue;
                }
                $result[substr($column, strrpos($column, '.') + 1)] = $row[$column];
            }
            return $result;
        }
    }

    function addJob(ActivityDB $db, int $id, string $type = 'Computer', int $entity = 0, array $changes = []): void
    {
        $tables = ['Computer' => 'glpi_computers', 'Monitor' => 'glpi_monitors', 'Printer' => 'glpi_printers', 'Phone' => 'glpi_phones', 'Peripheral' => 'glpi_peripherals', 'NetworkEquipment' => 'glpi_networkequipments'];
        $job = array_merge(['id' => $id, 'itemtype' => $type, 'items_id' => $id, 'glpi_b_connection_id' => $id % 2 === 0 ? 'west' : 'east', 'route_id' => 'route1', 'remote_items_id' => $id + 1000,
            'status' => 'pending', 'needs_recheck' => 0, 'attempts' => 0, 'started_at' => null, 'finished_at' => null, 'available_at' => null, 'date_mod' => '2026-10-06 12:00:00', 'last_error' => 'SECRET_RAW_ERROR https://user:password@example.test?user_token=secret'], $changes);
        $db->queue[] = $job;
        $db->assets[$tables[$type]][$id] = ['id' => $id, 'name' => 'Asset ' . $id, 'entities_id' => $entity, 'is_recursive' => 0, 'is_deleted' => 0, 'is_template' => 0, 'users_id' => 99, 'users_id_tech' => 99];
        $db->links[$job['glpi_b_connection_id'] . ':' . $type . ':' . $id] = ['id' => $id, 'remote_items_id' => $id + 1000, 'route_id' => 'route1', 'status' => 'synced', 'last_sync_at' => '2026-10-01 09:00:00'];
    }

    $_SESSION = ['glpiID' => 7, 'glpigroups' => [5]];
    Config::$values = ['glpib_connections' => json_encode([
        ['id' => 'west', 'name' => 'West office', 'base_url' => 'https://west.example.test/glpi/apirest.php', 'app_token' => 'SECRET_APP', 'user_token' => 'SECRET_USER'],
        ['id' => 'east', 'name' => 'East office', 'base_url' => 'https://east.example.test'],
    ]), 'glpib_name' => 'Legacy', 'glpib_user_token' => 'SECRET_LEGACY'];
    $db = new ActivityDB();
    for ($id = 1; $id <= 120; $id++) { addJob($db, $id); }
    addJob($db, 990, 'Computer', 9, ['glpi_b_connection_id' => 'hidden']);
    addJob($db, 991, 'Computer');
    $db->assets['glpi_computers'][991]['is_deleted'] = 1;
    addJob($db, 992, 'Computer');
    $db->assets['glpi_computers'][992]['is_template'] = 1;
    addJob($db, 993, 'Computer');
    unset($db->assets['glpi_computers'][993]);
    addJob($db, 994, 'Monitor');
    Session::$rights['monitor'] = 0;
    $reader = new SyncActivity($db);
    $filters = SyncActivity::filters([]);
    $list = $reader->listing($filters);
    check($list['all'] === 120 && $list['total'] === 120 && $list['counts']['pending'] === 120, 'Scope counts leaked.');
    check(!isset($list['options']['connection']['hidden'], $list['options']['type']['Monitor']), 'Filter options leaked.');
    check(array_keys($list['options']['entity']) === [0], 'Root entity scope lost.');
    check(count($list['rows']) === 50 && $list['rows'][0]['id'] === 120 && $list['rows'][49]['id'] === 71 && $list['older'] && !$list['newer'], 'Initial cursor page incorrect.');
    $older = $reader->listing(SyncActivity::filters(['before' => '71']));
    check($older['rows'][0]['id'] === 70 && $older['rows'][49]['id'] === 21 && $older['newer'] && $older['older'], 'Older cursor incorrect.');
    $last = $reader->listing(SyncActivity::filters(['before' => '21']));
    check(count($last['rows']) === 20 && !$last['older'], 'Last page incorrect.');
    $newer = $reader->listing(SyncActivity::filters(['after' => '20']));
    check($newer['rows'][0]['id'] === 70 && $newer['rows'][49]['id'] === 21, 'Nearest newer page incorrect.');
    check($reader->listing(SyncActivity::filters(['entity' => '9']))['total'] === 0, 'Forged entity broadened scope.');
    check($reader->listing(SyncActivity::filters(['connection' => 'west']))['total'] === 60, 'Connection filter counts incorrect.');
    check($reader->listing(SyncActivity::filters(['search' => 'Asset 120']))['total'] === 1, 'Search scope incorrect.');
    check($reader->detail(990) === null && $reader->detail(991) === null && $reader->detail(992) === null && $reader->detail(993) === null && $reader->detail(994) === null, 'Forged detail leaked.');
    check($reader->detail(120)['link_last_sync_at'] === '2026-10-01 09:00:00', 'Stored timestamp missing.');
    Session::$entities = [];
    $empty = new SyncActivity($db);
    check($empty->listing($filters)['all'] === 0 && $empty->detail(120) === null, 'Empty entity scope broadened.');
    Session::$entities = [0];

    $ownedDB = new ActivityDB();
    foreach ([1, 2, 3, 4] as $id) { addJob($ownedDB, $id); }
    $ownedDB->assets['glpi_computers'][1]['users_id'] = 7;
    $ownedDB->assets['glpi_computers'][2]['users_id_tech'] = 7;
    $ownedDB->groups = [['itemtype' => 'Computer', 'items_id' => 3, 'groups_id' => 5, 'type' => 1], ['itemtype' => 'Computer', 'items_id' => 4, 'groups_id' => 5, 'type' => 2]];
    Session::$rights['computer'] = READ_OWNED;
    $owned = new SyncActivity($ownedDB);
    check($owned->listing($filters)['total'] === 2 && $owned->detail(1) !== null && $owned->detail(3) !== null && $owned->detail(2) === null, 'Own-user/group scope incorrect.');
    Session::$rights['computer'] = READ_ASSIGNED;
    $assigned = new SyncActivity($ownedDB);
    check($assigned->listing($filters)['total'] === 2 && $assigned->detail(2) !== null && $assigned->detail(4) !== null && $assigned->detail(3) === null, 'Assigned-user/group scope incorrect.');
    Session::$rights['computer'] = 2;
    check((new SyncActivity($ownedDB))->listing($filters)['total'] === 0, 'Unsupported asset rights did not fail closed.');
    Session::$rights['computer'] = READ;

    foreach ([['before' => ['1']], ['id' => '99999999999999999999'], ['before' => '0'], ['before' => '1', 'after' => '2'], ['entity' => '-1'], ['state' => 'bad'], ['type' => 'User'], ['search' => str_repeat('x', 121)], ['connection' => "x\nsecret"], ['sort' => ['id']], ['limit' => '999999']] as $bad) {
        try { SyncActivity::filters($bad); throw new \LogicException('Bad filter accepted.'); } catch (\InvalidArgumentException) {}
    }
    $reads = count($db->requests);
    foreach (['config', 'interface', 'valid', 'user', 'cron', 'disabled'] as $denial) {
        $oldRights = Session::$rights;
        if ($denial === 'config') { Session::$rights['config'] = 0; }
        elseif ($denial === 'interface') { Session::$interface = 'helpdesk'; }
        elseif ($denial === 'valid') { Session::$valid = false; }
        elseif ($denial === 'user') { Session::$user = 0; }
        elseif ($denial === 'cron') { Session::$cron = true; }
        else { Session::$disabled = true; }
        try { new SyncActivity($db); throw new \LogicException('Denied access accepted.'); } catch (\RuntimeException) {}
        Session::$rights = $oldRights; Session::$interface = 'central'; Session::$valid = true; Session::$user = 7; Session::$cron = false; Session::$disabled = false;
    }
    check(count($db->requests) === $reads, 'Denied session performed DB reads.');
    foreach ([null, 0, 'old timer', time() - 1000] as $value) {
        $_SESSION['glpicrontimer'] = $value;
        foreach ([false, true] as $throw) {
            Html::$throw = $throw;
            try { SyncActivity::header('/front/syncactivity.php'); } catch (\RuntimeException) {}
            check(array_key_exists('glpicrontimer', $_SESSION) && $_SESSION['glpicrontimer'] === $value, 'Timer value/type not restored.');
        }
    }
    unset($_SESSION['glpicrontimer']);
    try { SyncActivity::header('/front/syncactivity.php'); } catch (\RuntimeException) {}
    check(!array_key_exists('glpicrontimer', $_SESSION) && CronTask::$forced === 0, 'Absent timer not restored or cron triggered.');
    Html::$throw = false;

    $row = $reader->detail(120);
    check($reader->remoteUrl($row) === 'https://west.example.test/glpi/front/computer.form.php?id=1120', 'Remote URL incorrect.');
    foreach (['javascript:alert(1)', 'https://user:password@example.test', 'https://example.test/?user_token=SECRET', 'https://example.test/#secret', "https://example.test/\nsecret", 'https://example.test/%0a', 'https://example.test/\\secret'] as $base) {
        Config::$values['glpib_connections'] = json_encode([['id' => 'west', 'name' => 'West', 'base_url' => $base]]);
        check((new SyncActivity($db))->remoteUrl($row) === null, 'Unsafe remote URL allowed.');
    }
    Config::$values['glpib_connections'] = json_encode([['id' => 'west', 'name' => 'West', 'base_url' => 'https://west.example.test']]);
    $reader = new SyncActivity($db);
    foreach ([['link_id' => null], ['link_remote_items_id' => 1], ['link_route_id' => 'other'], ['link_status' => 'blocked_identity'], ['link_status' => 'blocked_duplicate'], ['link_status' => 'blocked_missing_remote']] as $change) {
        check($reader->remoteUrl(array_merge($row, $change)) === null, 'Conflicting/missing identity produced remote link.');
    }
    check(SyncActivity::assetPath('User', 1) === null, 'Native form path not allowlisted.');
    foreach (['done', 'blocked'] as $state) { check(str_contains(SyncActivity::reason(array_merge($row, ['status' => $state, 'needs_recheck' => 1])), 'older result'), 'Dirty terminal result overstated.'); }
    check(str_contains(SyncActivity::reason(array_merge($row, ['status' => 'done'])), 'does not prove'), 'Done overstated changes.');
    check(str_contains(SyncActivity::reason(array_merge($row, ['status' => 'retry'])), 'may already have changed'), 'Retry implied no update.');
    unset(Config::$values['glpib_connections']);
    check((new SyncActivity($db))->connectionName('west') === 'Connection unavailable', 'Legacy config should remain read-only/unmigrated.');
    check(isset(Config::$values['glpib_user_token']) && !isset(Config::$values['glpib_connections']), 'Legacy config was migrated.');
    Config::$values['glpib_connections'] = json_encode([['id' => 'west', 'name' => 'West', 'base_url' => 'https://west.example.test']]);

    // Virtual bootstrap avoids loading GLPI, connecting to MySQL, or creating files.
    class ActivityBootstrap
    {
        public $context;
        private int $position = 0;
        public function stream_open($path, $mode, $options, &$opened): bool { check($mode === 'rb' && str_ends_with($path, '/inc/includes.php'), 'Unexpected bootstrap access.'); return true; }
        public function stream_read($count): string { $text = substr('<?php ', $this->position, $count); $this->position += strlen($text); return $text; }
        public function stream_eof(): bool { return $this->position >= 6; }
        public function stream_stat(): array { return []; }
        public function stream_set_option(...$args): bool { return false; }
    }
    stream_wrapper_register('activitybootstrap', ActivityBootstrap::class);
    define('GLPI_ROOT', 'activitybootstrap://root');
    $GLOBALS['DB'] = $db;
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'PHP_SELF' => '/plugins/assetsync20/front/syncactivity.php'];
    $_GET = ['connection' => 'west', 'id' => '120'];
    ob_start(); include __DIR__ . '/../front/syncactivity.php'; $output = ob_get_clean();
    check(str_contains($output, 'Refresh activity') && str_contains($output, 'What happened / What to do') && str_contains($output, 'Last verified field success') && str_contains($output, 'Not recorded'), 'Current Work UI missing.');
    $stylesheet = '/plugins/assetsync20/css/assetsync20.css?v=' . hash_file('sha256', __DIR__ . '/../public/css/assetsync20.css');
    check(str_contains($output, 'rel="stylesheet" href="' . $stylesheet . '"'), 'Activity stylesheet did not use a content-versioned plugin URL.');
    foreach (['connections', 'entities', 'asset types', 'statuses'] as $label) {
        check(str_contains($output, 'All ' . $label . '</option>'), 'Filter option label is unclear or incorrectly pluralized.');
    }
    check(!str_contains($output, 'SECRET') && !str_contains($output, 'Recorded Attempts') && !str_contains($output, 'Sample') && !str_contains($output, 'Step results'), 'Forbidden output exposed.');
    check(str_contains($output, 'connection=west&amp;before=') && str_contains($output, 'id=120'), 'Navigation filters/details not preserved.');
    $db->throw = true;
    $_GET = [];
    ob_start(); include __DIR__ . '/../front/syncactivity.php'; $failure = ob_get_clean();
    check(str_contains($failure, 'Activity data is unavailable') && !str_contains($failure, 'SECRET'), 'Database exception leaked.');
    $db->throw = false;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $before = count($db->requests);
    ob_start(); include __DIR__ . '/../front/syncactivity.php'; $post = ob_get_clean();
    check(str_contains($post, 'local reads only') && count($db->requests) === $before, 'POST performed work.');

    $regressions = [];
    if (!str_starts_with(SyncActivity::reason(array_merge($row, ['status' => 'running'])), 'Marked as running')) {
        $regressions[] = 'A saved running state claimed a live worker.';
    }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['id' => '120'];
    foreach (['2026-02-30 12:00:00', '2026-13-01 12:00:00', '2026-01-01 25:00:00', '2026-01-01 12:60:00', '0000-01-01 12:00:00'] as $invalidDate) {
        $db->queue[119]['started_at'] = $invalidDate;
        ob_start(); include __DIR__ . '/../front/syncactivity.php'; $dated = ob_get_clean();
        if (str_contains($dated, $invalidDate)) { $regressions[] = 'Invalid stored calendar date was displayed: ' . $invalidDate; }
    }
    $db->queue[119]['started_at'] = '2024-02-29 12:00:00';
    ob_start(); include __DIR__ . '/../front/syncactivity.php'; $dated = ob_get_clean();
    check(str_contains($dated, '2024-02-29 12:00:00'), 'Valid leap day was hidden.');

    $recursiveDB = new ActivityDB();
    $recursiveDB->entityParents = [0 => 0, 1 => 0, 2 => 1, 9 => 0];
    $recursiveDB->entities[1] = 'Parent';
    $savedRights = Session::$rights;
    foreach (['computer', 'monitor', 'printer', 'phone', 'peripheral', 'networking'] as $right) { Session::$rights[$right] = READ; }
    Session::$entities = [2];
    addJob($recursiveDB, 1, 'Computer', 2);
    foreach (['Computer', 'Monitor', 'Peripheral', 'Printer', 'Phone', 'NetworkEquipment'] as $index => $type) {
        addJob($recursiveDB, 10 + $index, $type, 0);
        $table = array_key_first(array_filter($recursiveDB->assets, static fn ($assets) => isset($assets[10 + $index])));
        $recursiveDB->assets[$table][10 + $index]['is_recursive'] = 1;
    }
    addJob($recursiveDB, 3, 'Computer', 0);
    addJob($recursiveDB, 4, 'Computer', 9);
    $recursiveDB->assets['glpi_computers'][4]['is_recursive'] = 1;
    $recursive = new SyncActivity($recursiveDB);
    $recursiveList = $recursive->listing($filters);
    if ($recursiveList['total'] !== 7 || $recursiveList['counts']['pending'] !== 7 || !isset($recursiveList['options']['entity'][0])) {
        $regressions[] = 'Recursive ancestor assets were omitted from counts, options or rows.';
    }
    foreach (range(10, 15) as $id) { if ($recursive->detail($id) === null) { $regressions[] = 'Recursive ancestor detail omitted: ' . $id; } }
    check($recursive->detail(3) === null && $recursive->detail(4) === null && $recursive->listing(SyncActivity::filters(['entity' => '9']))['total'] === 0, 'Recursive scope exposed a nonrecursive or unrelated asset.');
    foreach (['bad', null, false, 1.5, -1, '4294967296'] as $parent) {
        $recursiveDB->entityParents[2] = $parent;
        try { new SyncActivity($recursiveDB); throw new \LogicException('Invalid parent accepted.'); } catch (\RuntimeException) {}
    }
    foreach ([[0 => 0, 2 => 1], [0 => 0, 2 => 1, 1 => 2], [0 => 0, 2 => 2], [0 => 1, 1 => 0, 2 => 1]] as $parents) {
        $recursiveDB->entityParents = $parents;
        try { new SyncActivity($recursiveDB); throw new \LogicException('Missing/cyclic hierarchy accepted.'); } catch (\RuntimeException) {}
    }
    Session::$entities = [0];
    Session::$rights = $savedRights;
    check($regressions === [], implode("\n", $regressions));

    $dirtyDB = new ActivityDB();
    foreach (['pending', 'done', 'blocked', 'done', 'blocked', 'retry', 'running'] as $index => $state) {
        addJob($dirtyDB, $index + 1, 'Computer', 0, ['status' => $state, 'needs_recheck' => in_array($index + 1, [2, 3, 6, 7], true) ? 1 : 0]);
    }
    $dirty = new SyncActivity($dirtyDB);
    $dirtyList = $dirty->listing($filters);
    check($dirtyList['counts'] === ['pending' => 3, 'running' => 1, 'retry' => 1, 'blocked' => 1, 'done' => 1], 'Dirty terminal counts were not waiting work.');
    check(array_column($dirty->listing(SyncActivity::filters(['state' => 'pending']))['rows'], 'id') === [3, 2, 1], 'Waiting filter omitted dirty terminal rows.');
    check(array_column($dirty->listing(SyncActivity::filters(['state' => 'done']))['rows'], 'id') === [4]
        && array_column($dirty->listing(SyncActivity::filters(['state' => 'blocked']))['rows'], 'id') === [5], 'Terminal filter included dirty waiting work.');
    $onlyDirty = new ActivityDB();
    addJob($onlyDirty, 1, 'Computer', 0, ['status' => 'done', 'needs_recheck' => 1]);
    check((new SyncActivity($onlyDirty))->listing($filters)['options']['state'] === ['pending' => 'Waiting'], 'Dirty terminal filter option used the old state.');
    $stale = array_merge($dirty->detail(5), ['link_status' => 'blocked_missing_remote']);
    check(str_starts_with(SyncActivity::reason($stale), 'Stored link condition') && str_contains(SyncActivity::reason($stale), 'not a confirmed error from this attempt'), 'Stale link condition was attributed to the queue attempt.');
    check(SyncActivity::STATES['retry'] === 'Retry pending' && str_starts_with(SyncActivity::reason($dirty->detail(6)), 'Waiting for another scheduled attempt'), 'Retry claimed an active or manual update.');
    $GLOBALS['DB'] = $dirtyDB;
    $_GET = ['id' => '2'];
    ob_start(); include __DIR__ . '/../front/syncactivity.php'; $dirtyOutput = ob_get_clean();
    check(str_contains($dirtyOutput, 'Waiting: recheck requested') && str_contains($dirtyOutput, 'Stored queue state</th><td>done</td>'), 'Dirty row/detail lost current or original state.');
    $_GET = ['search' => 'does not exist'];
    ob_start(); include __DIR__ . '/../front/syncactivity.php'; $emptyOutput = ob_get_clean();
    check(str_contains($emptyOutput, 'No work matches these filters.'), 'Empty filter wording incorrect.');
    $_GET = ['before' => '1'];
    ob_start(); include __DIR__ . '/../front/syncactivity.php'; $cursorOutput = ob_get_clean();
    check(str_contains($cursorOutput, 'No rows on this page. Choose Latest.'), 'Empty cursor wording incorrect.');

    $mixedDB = new ActivityDB();
    Session::$rights['monitor'] = READ;
    for ($id = 1; $id <= 120; $id++) { addJob($mixedDB, $id, $id % 2 ? 'Computer' : 'Monitor'); }
    $mixed = new SyncActivity($mixedDB);
    check(array_column($mixed->listing(SyncActivity::filters([]))['rows'], 'id') === range(120, 71), 'Mixed-type latest page incorrect.');
    check(array_column($mixed->listing(SyncActivity::filters(['before' => '71']))['rows'], 'id') === range(70, 21), 'Mixed-type older page incorrect.');
    check(array_column($mixed->listing(SyncActivity::filters(['after' => '20']))['rows'], 'id') === range(70, 21), 'Mixed-type nearest newer page incorrect.');
    Session::$rights = $savedRights;
    $legacyDB = new ActivityDB();
    $legacyDB->hasRecheck = false;
    $legacyDB->entityParents[0] = null;
    foreach (['done', 'blocked', 'pending'] as $index => $state) {
        addJob($legacyDB, $index + 1, 'Computer', 0, ['status' => $state]);
        unset($legacyDB->queue[$index]['needs_recheck']);
    }
    $legacy = new SyncActivity($legacyDB);
    $legacyList = $legacy->listing(SyncActivity::filters([]));
    check($legacyList['recheck'] === null && $legacyList['counts'] === ['pending' => 1, 'running' => 0, 'retry' => 0, 'blocked' => 1, 'done' => 1], 'Legacy recheck evidence or raw state was invented.');
    check(array_column($legacy->listing(SyncActivity::filters(['state' => 'pending']))['rows'], 'id') === [3]
        && array_column($legacy->listing(SyncActivity::filters(['state' => 'done']))['rows'], 'id') === [1]
        && array_column($legacy->listing(SyncActivity::filters(['state' => 'blocked']))['rows'], 'id') === [2], 'Legacy status filters changed the stored state.');
    check(array_key_exists('needs_recheck', $legacy->detail(1)) && $legacy->detail(1)['needs_recheck'] === null, 'Legacy detail fabricated a recheck flag.');
    $GLOBALS['DB'] = $legacyDB;
    $_GET = ['id' => '1'];
    ob_start(); include __DIR__ . '/../front/syncactivity.php'; $legacyOutput = ob_get_clean();
    check(str_contains($legacyOutput, 'Needs another check</dt><dd>Not recorded</dd>')
        && str_contains($legacyOutput, 'Needs another check</th><td>Not recorded</td>')
        && !str_contains($legacyOutput, 'Waiting: recheck requested'), 'Legacy page claimed an unrecorded recheck value.');
    echo "Sync Activity read-only tests passed.\n";
}
