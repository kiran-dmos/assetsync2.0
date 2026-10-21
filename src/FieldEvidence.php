<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

/** Local model-save facts, not mapping policy or a replacement for GLPI authorization. */
final class FieldEvidence
{
    private const TABLE = 'glpi_plugin_assetsync20_fieldevidence';
    private const CONTEXT = 'plugin:assetsync20';
    private const MARKER = 'field_evidence_capture';
    private const MAX_PENDING = 32;
    private static array $frames = [];
    private static array $owned = [];
    private static array $origins = [];
    private static bool $callbackRegistered = false;

    public static function install(): bool
    {
        global $DB;
        if (!isset($DB)) { return true; }
        if (!$DB->tableExists(self::TABLE) && !$DB->doQuery('CREATE TABLE `' . self::TABLE . '` (
            `computer_id` INT UNSIGNED NOT NULL,
            `field_key` VARCHAR(80) NOT NULL,
            `model_class` VARCHAR(160) NOT NULL,
            `generation` BIGINT UNSIGNED NOT NULL,
            `evidence` MEDIUMTEXT NOT NULL,
            PRIMARY KEY (`computer_id`, `field_key`),
            KEY `field_model` (`model_class`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci')) { return false; }
        $index = self::one('SHOW INDEX FROM `' . self::TABLE . '` WHERE Key_name = \'field_model\' AND Seq_in_index = 1');
        if ($index === null && !$DB->doQuery('ALTER TABLE `' . self::TABLE . '` ADD KEY `field_model` (`model_class`)')) { return false; }
        if ($index !== null && $index['Column_name'] !== 'model_class') { return false; }
        self::coverage(false);
        return true;
    }

    public static function activate(): void { self::coverage(true); }
    public static function deactivate(): void { self::coverage(false); }

    private static function coverage(bool $active): void
    {
        $old = self::marker();
        $marker = ['protocol' => 1, 'installation' => $old['installation'] ?? bin2hex(random_bytes(16)),
            'epoch' => bin2hex(random_bytes(16)), 'active' => $active];
        $json = self::json($marker);
        \Config::setConfigurationValues(self::CONTEXT, [self::MARKER => $json]);
        if (self::marker() !== $marker) { throw new \RuntimeException('Field capture coverage could not be saved.'); }
    }

    private static function marker(): ?array
    {
        global $DB;
        // Even absence/inactivity must be a CURRENT read, never an older RR Config snapshot.
        // Shared metadata readers do not take a global exclusive lock through the model call.
        $row = self::one('SELECT `value` FROM `glpi_configs` WHERE `context`=' . $DB->quote(self::CONTEXT)
            . ' AND `name`=' . $DB->quote(self::MARKER) . ' LOCK IN SHARE MODE');
        $raw = $row['value'] ?? null;
        // A package deployed before its supported Update remains inert.
        if ($raw === null) { return null; }
        $marker = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($marker) || ($marker['protocol'] ?? null) !== 1 || !is_bool($marker['active'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $marker['epoch'] ?? '')
            || !preg_match('/^[a-f0-9]{32}$/D', $marker['installation'] ?? '')) {
            throw new \RuntimeException('Field capture coverage is unavailable.');
        }
        return $marker;
    }

    /** Include retained generated classes even if the corresponding mapping/container is now disabled. */
    public static function hookTypes(): array
    {
        global $DB;
        $types = ['Computer' => true];
        if (!isset($DB) || !method_exists($DB, 'tableExists')) { return array_keys($types); }
        if ($DB->tableExists(self::TABLE)) {
            foreach (self::rows('SELECT DISTINCT `model_class` FROM `' . self::TABLE . '`') as $row) {
                if (self::modelClass($row['model_class'])) { $types[$row['model_class']] = true; }
            }
        }
        if ($DB->tableExists('glpi_plugin_fields_containers') && class_exists('PluginFieldsContainer')) {
            foreach (self::rows('SELECT * FROM `glpi_plugin_fields_containers`') as $container) {
                $applies = json_decode($container['itemtypes'], true);
                if (is_array($applies) && in_array('Computer', $applies, true)) {
                    $class = \PluginFieldsContainer::getClassname('Computer', $container['name']);
                    if (self::modelClass($class)) { $types[$class] = true; }
                }
            }
        }
        return array_keys($types);
    }

    /** Register every potential selected field BEFORE preparation/rules/model SQL. */
    public static function before(object $item): void
    {
        global $DB;
        $class = $item::class;
        if (!self::modelClass($class)) { return; }
        try {
            $marker = self::marker();
            if (!$marker || !$marker['active']) { return; }
            $qualifying = self::idle();
            $fields = $item->fields ?? [];
            $id = (int) ($class === 'Computer' ? ($fields['id'] ?? 0) : ($fields['items_id'] ?? 0));
            if ($id < 1 || ($class !== 'Computer' && ($fields['itemtype'] ?? '') !== 'Computer')) { return; }
            $connection = self::connection();
            $frame = [];
            foreach (self::descriptors($class, $fields) as $descriptor) {
                // A previous clean job may have handed off a budget-spilled post receipt.
                // Pre-only/crashed/overlapping attempts are never retired by this fallback.
                self::settle($id, $descriptor['key']);
                $token = bin2hex(random_bytes(16));
                $head = self::register($id, $descriptor, $token, $marker);
                $owner = $id . ':' . $descriptor['key'] . ':' . $token;
                self::$owned[$owner] = ['id' => $id, 'descriptor' => $descriptor, 'token' => $token,
                    'generation' => $head['generation'], 'connection' => $connection, 'db' => $DB,
                    'epoch' => $marker['epoch'], 'stage' => 'pre', 'ready' => false];
                self::callback();
                $entry = self::$owned[$owner];
                $entry['from_epoch'] = self::epoch();
                $entry['history'] = self::history($id, $descriptor);
                $fresh = self::snapshot($id, $descriptor);
                $entry['old_hash'] = $fresh['hash'];
                $entry['meta'] = $fresh['meta'];
                $entry['ready'] = array_key_exists($descriptor['column'], $fields)
                    && self::valueHash($fields[$descriptor['column']]) === $fresh['hash']
                    && self::rowIdentity($fields, $descriptor) === $fresh['row_identity'];
                $entry['human_origin'] = $qualifying;
                $entry['scope'] = self::$origins === [] ? (self::browserEdit() ? null : []) : end(self::$origins);
                self::$owned[$owner] = $entry;
                $frame[] = $owner;
            }
            self::$frames[spl_object_id($item)][] = $frame;
        } catch (\Throwable) {
            // false alone is not an abort: later plugin callbacks still run in core's hook loop.
            $item->input = false;
            throw new \RuntimeException('This save was cancelled because field change tracking is unavailable.');
        }
    }

    public static function after(object $item): void
    {
        $object = spl_object_id($item);
        if (empty(self::$frames[$object])) { return; }
        $frame = array_pop(self::$frames[$object]);
        if (self::$frames[$object] === []) { unset(self::$frames[$object]); }
        foreach ($frame as $owner) {
            $entry = self::$owned[$owner];
            $entry['stage'] = 'post';
            self::$owned[$owner] = $entry;
            try {
                if (!self::sameConnection($entry)) { continue; }
                $descriptor = $entry['descriptor'];
                $fresh = self::snapshot($entry['id'], $descriptor);
                $end = self::epoch();
                $column = $descriptor['column'];
                $input = is_array($item->input ?? null) ? $item->input : [];
                $old = is_array($item->oldvalues ?? null) ? $item->oldvalues : [];
                $changed = in_array($column, $item->updates ?? [], true);
                $valid = $entry['ready'] && $end >= $entry['from_epoch'] && $fresh['meta'] === $entry['meta']
                    && $changed && array_key_exists($column, $input) && array_key_exists($column, $old)
                    && self::valueHash($old[$column]) === $entry['old_hash']
                    && self::valueHash($input[$column]) === $fresh['hash'] && $fresh['hash'] !== $entry['old_hash'];
                $unchanged = $entry['ready'] && !$changed && $fresh['meta'] === $entry['meta'] && $fresh['hash'] === $entry['old_hash'];
                $head = self::head($entry['id'], $descriptor['key']);
                if (!self::uninterrupted($head, $entry)) { continue; }
                $origin = $valid ? self::origin($entry, $fresh['hash'], $head['generation'], $end) : null;
                $kind = $unchanged ? 'unchanged' : ($valid && $origin !== null ? 'change' : 'unknown');
                $candidate = ['value' => $fresh['hash'], 'meta' => $fresh['meta'],
                    'kind' => $kind, 'history' => $entry['history'],
                    'projection' => $kind === 'change' ? self::projection($old[$column], $input[$column]) : null, 'watermark' => $entry['history']['latest'],
                    'origin' => $origin, 'epoch' => $entry['epoch'],
                    'generation' => $head['generation'] + 1, 'recoverable' => false];
                $state = $head['state'];
                $state['attempts'][$entry['token']]['candidate'] = $candidate;
                if (self::cas($head, $state)) {
                    self::$owned[$owner]['generation']++;
                    self::$owned[$owner]['stage'] = 'candidate';
                    // Exact CAS bytes for the single request-end handoff statement; no field values.
                    self::$owned[$owner]['post_head'] = array_replace($head, [
                        'generation' => $head['generation'] + 1, 'raw' => self::json($state), 'state' => $state]);
                }
            } catch (\Throwable) {
                // The model may already have written. Keep the registered attempt unknown, never roll it back here.
            }
        }
    }

    /** Local reads validate evidence only; they NEVER settle or remove an attempt. */
    public static function read(int $computerId, string $key): ?array
    {
        try {
            if (!self::idle()) { return null; }
            $marker = self::marker();
            if (!$marker || !$marker['active']) { return null; }
            $descriptor = self::descriptorForKey($key);
            $head = self::head($computerId, $key);
            $state = $head['state'];
            $current = $state['current'];
            if ($state['attempts'] !== [] || $state['overflow'] || !$current || $state['epoch'] !== $marker['epoch']
                || $current['revision'] !== $head['generation']) { return null; }
            $fresh = self::snapshot($computerId, $descriptor);
            $history = self::history($computerId, $descriptor, $current['anchor']['id']);
            if ($current['value'] !== $fresh['hash'] || $current['meta'] !== $fresh['meta']
                || $history['latest'] !== $current['anchor']['id'] || $history['anchor'] !== $current['anchor']) { return null; }
            // A second CAS-fence read closes intervening cooperative registration, including edit/revert.
            $again = self::head($computerId, $key);
            return $again === $head && self::marker() === $marker ? $current['origin'] : null;
        } catch (\Throwable) { return null; }
    }

    /** Exact trusted scopes are process-local, never inferred from a username or queue suppression. */
    public static function withOrigin(int $id, string $key, string $beforeHash, string $afterHash, array $origin, callable $operation): mixed
    {
        if (!self::validOrigin($origin) || !preg_match('/^[a-f0-9]{64}$/D', $beforeHash)
            || !preg_match('/^[a-f0-9]{64}$/D', $afterHash)) { throw new \InvalidArgumentException('Invalid field origin scope.'); }
        self::$origins[] = ['id' => $id, 'key' => $key, 'before' => $beforeHash, 'after' => $afterHash, 'origin' => $origin];
        try { return $operation(); } finally { array_pop(self::$origins); }
    }

    public static function withoutOrigin(callable $operation): mixed
    {
        self::$origins[] = [];
        try { return $operation(); } finally { array_pop(self::$origins); }
    }

    private static function origin(array $entry, string $after, int $revision, int $end): ?array
    {
        if ($entry['scope'] !== null) {
            $scope = $entry['scope'];
            return ($scope['id'] ?? null) === $entry['id'] && ($scope['key'] ?? null) === $entry['descriptor']['key']
                && ($scope['before'] ?? null) === $entry['old_hash'] && ($scope['after'] ?? null) === $after ? $scope['origin'] : null;
        }
        if (!$entry['human_origin']) { return null; }
        $marker = self::marker();
        return ['identity' => $marker['installation'] . ':' . $entry['token'], 'revision' => $revision, 'kind' => 'user',
            'from_epoch' => $entry['from_epoch'], 'to_epoch' => $end];
    }

    private static function validOrigin(array $origin): bool
    {
        return array_keys($origin) === ['identity', 'revision', 'kind', 'from_epoch', 'to_epoch']
            && is_string($origin['identity']) && preg_match('/^[a-f0-9]{32}:[a-f0-9]{32}$/D', $origin['identity'])
            && is_int($origin['revision']) && $origin['revision'] > 0 && $origin['kind'] === 'user'
            && is_int($origin['from_epoch']) && is_int($origin['to_epoch'])
            && $origin['from_epoch'] >= 0 && $origin['to_epoch'] >= $origin['from_epoch'];
    }

    private static function browserEdit(): bool
    {
        return function_exists('isAPI') && function_exists('isCommandLine') && !\isAPI() && !\isCommandLine()
            && class_exists('Session') && !\Session::isCron() && !\Session::isInventory()
            && (int) \Session::getLoginUserID(true) > 0;
    }

    private static function callback(): void
    {
        if (self::$callbackRegistered) { return; }
        self::$callbackRegistered = true;
        register_shutdown_function([self::class, 'finishRequest']);
    }

    /** One bounded pass, this request's tokens only. No sleep, retry, transaction ownership or GET writes. */
    public static function finishRequest(): void
    {
        $fatal = error_get_last();
        if (in_array($fatal['type'] ?? null, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)
            || strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') { return; }
        self::finishOwned();
    }

    private static function finishOwned(): void
    {
        $deadline = hrtime(true) + 100_000_000;
        try { self::deferCandidates(); }
        catch (\Throwable) { return; }
        foreach (array_keys(self::$owned) as $owner) {
            if (hrtime(true) >= $deadline) { break; }
            $entry = self::$owned[$owner];
            try {
                if (!self::sameConnection($entry) || !self::idle()) { continue; }
                if (self::complete($entry)) { unset(self::$owned[$owner]); }
            } catch (\Throwable) { /* Durable pending is safer than guessing after storage/context failure. */ }
        }
        self::pruneFrames();
    }

    /** Existing job boundaries call this AFTER their model transactions/wrappers, not after process exit. */
    public static function finishWork(): void
    {
        if (self::$owned === []) { return; }
        $fatal = error_get_last();
        if (in_array($fatal['type'] ?? null, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) { return; }
        // A legacy web-Cron GET may finish its own existing job, never another request's token.
        self::finishOwned();
        foreach (self::$owned as $owner => $entry) {
            // The exact post receipt is durably handed off, not silently discarded or made successful.
            if (!empty($entry['handed_off'])) { unset(self::$owned[$owner]); }
        }
        self::pruneFrames();
    }

    private static function pruneFrames(): void
    {
        foreach (self::$frames as $object => $frames) {
            foreach ($frames as $index => $frame) {
                $frames[$index] = array_values(array_filter($frame, static fn (string $owner): bool => isset(self::$owned[$owner])));
                if ($frames[$index] === []) { unset($frames[$index]); }
            }
            if ($frames === []) { unset(self::$frames[$object]); }
            else { self::$frames[$object] = array_values($frames); }
        }
    }

    /** A clean callback hands off only exact post-proven candidates, in one bounded CAS statement. */
    private static function deferCandidates(): void
    {
        global $DB;
        $where = []; $owners = []; $bytes = 0;
        foreach (self::$owned as $owner => $entry) {
            if (!isset($entry['post_head']) || !self::sameConnection($entry)) { continue; }
            $head = $entry['post_head'];
            $predicate = '`computer_id`=' . $head['id'] . ' AND `field_key`=' . $DB->quote($head['key'])
                . ' AND `generation`=' . $head['generation'] . ' AND SHA2(`evidence`,256)=' . $DB->quote(hash('sha256', $head['raw']));
            $bytes += strlen($predicate);
            if ($bytes > 262144 || count($owners) >= 1024) { break; }
            $where[] = '(' . $predicate . ')'; $owners[] = $owner;
        }
        if ($where === [] || !self::idle()) { return; }
        // Each exact post head contains ONE owned token; JSON functions update only its handoff metadata.
        $path = "CONCAT('$.attempts.\"',JSON_UNQUOTE(JSON_EXTRACT(JSON_KEYS(`evidence`,'$.attempts'),'$[0]')),'\".candidate.')";
        self::sql('UPDATE `' . self::TABLE . '` SET `evidence`=JSON_SET(`evidence`,CONCAT(' . $path
            . ",'recoverable'),JSON_EXTRACT('true','$'),CONCAT(" . $path . ",'generation'),`generation`+1),`generation`=`generation`+1"
            . ' WHERE CONNECTION_ID()=' . self::connection() . ' AND (' . implode(' OR ', $where) . ')');
        $allHandedOff = $DB->affectedRows() === count($owners);
        foreach ($owners as $owner) {
            self::$owned[$owner]['generation']++;
            self::$owned[$owner]['handed_off'] = $allHandedOff;
            unset(self::$owned[$owner]['post_head']);
        }
    }

    /** Explicit metadata-only POST/worker fallback, not an asset mutation or a GET side effect. */
    public static function settle(int $computerId, string $key): bool
    {
        global $DB;
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
        if ($method !== 'POST' && !($method === '' && PHP_SAPI === 'cli')) { return false; }
        try {
            if (!self::idle()) { return false; }
            $head = self::head($computerId, $key); $state = $head['state'];
            if ($state['overflow'] || count($state['attempts']) !== 1) { return false; }
            $token = array_key_first($state['attempts']); $attempt = $state['attempts'][$token];
            $candidate = $attempt['candidate'];
            if ($attempt['tainted'] || !$candidate || !$candidate['recoverable']
                || $candidate['generation'] !== $head['generation']) { return false; }
            $descriptor = self::descriptorForKey($key);
            return self::complete(['id' => $computerId, 'descriptor' => $descriptor, 'token' => $token,
                'generation' => $head['generation'], 'epoch' => $candidate['epoch'], 'stage' => 'candidate',
                'ready' => false, 'connection' => self::connection(), 'db' => $DB]);
        } catch (\Throwable) { return false; }
    }

    private static function complete(array $entry): bool
    {
        $head = self::head($entry['id'], $entry['descriptor']['key']);
        $state = $head['state'];
        $token = $entry['token'];
        if (!isset($state['attempts'][$token])) { return true; } // caller rollback, or already completed
        if ($state['attempts'][$token]['tainted']) { return self::retireTainted($entry); }
        $marker = self::marker();
        $fresh = self::snapshot($entry['id'], $entry['descriptor']);
        $history = self::history($entry['id'], $entry['descriptor']);
        $eligible = self::uninterrupted($head, $entry) && $marker && $marker['active'] && $marker['epoch'] === $entry['epoch'];
        $candidate = $state['attempts'][$token]['candidate'] ?? null;
        $current = null;
        // Unknown legacy/foreign-context post receipts can only retire unknown. They never publish an origin.
        // Qualified/inherited change receipts below still REQUIRE exact parent History corroboration.
        if ($eligible && $candidate && $candidate['kind'] === 'unchanged' && $candidate['value'] === $fresh['hash']
            && $candidate['meta'] === $fresh['meta'] && $history === $candidate['history']) {
            $current = $state['current'];
            if ($current !== null && ($current['value'] !== $fresh['hash'] || $current['meta'] !== $fresh['meta']
                || $current['anchor'] !== $history['anchor'])) { $current = null; }
            if ($current !== null) { $current['revision'] = $head['generation'] + 1; }
        } elseif ($eligible && $candidate && $candidate['kind'] === 'change'
            && $candidate['value'] === $fresh['hash'] && $candidate['meta'] === $fresh['meta']) {
            $rows = self::historyAfter($entry['id'], $entry['descriptor'], $candidate['watermark']);
            // Missing delayed parent History keeps the candidate unfinished for explicit later recovery.
            if ($rows === []) { return false; }
            if (count($rows) === 1 && self::projection($rows[0]['old_value'], $rows[0]['new_value'], false) === $candidate['projection']
                && ($rows[0]['itemtype_link'] ?? '') === '' && (int) $rows[0]['linked_action'] === 0
                && (int) $rows[0]['id'] === $history['latest'] && $candidate['origin'] !== null) {
                $current = ['value' => $fresh['hash'], 'meta' => $fresh['meta'], 'origin' => $candidate['origin'],
                    'anchor' => self::anchor($rows[0]), 'revision' => $head['generation'] + 1];
            }
        } elseif ($eligible && !$candidate && $entry['ready']
            && $fresh['hash'] === $entry['old_hash'] && $fresh['meta'] === $entry['meta']
            && $history === $entry['history'] && $state['current'] !== null
            && $state['current']['value'] === $fresh['hash'] && $state['current']['meta'] === $fresh['meta']
            && $state['current']['anchor'] === $history['anchor']) {
            $current = $state['current'];
            $current['revision'] = $head['generation'] + 1;
        }
        unset($state['attempts'][$token]);
        $state['current'] = $current;
        if (!self::sameConnection($entry) || !self::idle() || self::marker() !== $marker) { return false; }
        return self::cas($head, $state) || self::retireTainted($entry);
    }

    private static function retireTainted(array $entry): bool
    {
        global $DB;
        if (!self::sameConnection($entry) || !self::idle()) { return false; }
        // Also handles a new overlapping registration racing the completion CAS.
        $path = '$.attempts."' . $entry['token'] . '"';
        self::sql('UPDATE `' . self::TABLE . '` SET `generation`=`generation`+1,`evidence`=JSON_REMOVE('
            . "JSON_SET(`evidence`,'$.current',JSON_EXTRACT('null','$'))," . $DB->quote($path) . ')'
            . ' WHERE `computer_id`=' . $entry['id'] . ' AND `field_key`=' . $DB->quote($entry['descriptor']['key'])
            . ' AND JSON_EXTRACT(`evidence`,' . $DB->quote($path . '.tainted') . ")=JSON_EXTRACT('true','$')");
        return $DB->affectedRows() === 1;
    }

    private static function register(int $id, array $descriptor, string $token, array $marker): array
    {
        global $DB;
        $blank = self::json(['epoch' => $marker['epoch'], 'attempts' => [], 'overflow' => false, 'current' => null]);
        self::sql('INSERT IGNORE INTO `' . self::TABLE . '` (`computer_id`,`field_key`,`model_class`,`generation`,`evidence`) VALUES ('
            . $id . ',' . $DB->quote($descriptor['key']) . ',' . $DB->quote($descriptor['class']) . ',0,' . $DB->quote($blank) . ')');
        for ($retry = 0; $retry < 8; $retry++) {
            $head = self::head($id, $descriptor['key']);
            $state = $head['state'];
            if ($state['epoch'] !== $marker['epoch']) { $state['epoch'] = $marker['epoch']; $state['current'] = null; }
            $overlap = $state['attempts'] !== [] || $state['overflow'];
            if ($overlap) {
                $state['current'] = null;
                foreach ($state['attempts'] as &$attempt) { $attempt['tainted'] = true; }
                unset($attempt);
            }
            if (count($state['attempts']) >= self::MAX_PENDING || $state['overflow']) {
                $state['overflow'] = true;
                if (self::cas($head, $state)) { throw new \RuntimeException('Field capture pending limit reached.'); }
                continue;
            }
            $state['attempts'][$token] = ['tainted' => $overlap, 'candidate' => null];
            if (self::cas($head, $state)) { return self::head($id, $descriptor['key']); }
        }
        throw new \RuntimeException('Field capture registration is busy.');
    }

    private static function uninterrupted(array $head, array $entry): bool
    {
        return $head['generation'] === $entry['generation'] && $head['state']['epoch'] === $entry['epoch']
            && !$head['state']['overflow'] && count($head['state']['attempts']) === 1
            && isset($head['state']['attempts'][$entry['token']]) && !$head['state']['attempts'][$entry['token']]['tainted'];
    }

    private static function head(int $id, string $key): array
    {
        global $DB;
        if ($id < 1) { throw new \RuntimeException('Field capture identity unavailable.'); }
        $row = self::one('SELECT * FROM `' . self::TABLE . '` WHERE `computer_id`=' . $id . ' AND `field_key`=' . $DB->quote($key) . ' FOR UPDATE');
        if (!$row || strlen($row['evidence']) > 65536) { throw new \RuntimeException('Field capture head unavailable.'); }
        $state = json_decode($row['evidence'], true, 20, JSON_THROW_ON_ERROR);
        if (!is_array($state) || !is_array($state['attempts'] ?? null) || !is_bool($state['overflow'] ?? null)
            || !array_key_exists('current', $state)) { throw new \RuntimeException('Field capture head invalid.'); }
        return ['id' => $id, 'key' => $key, 'generation' => (int) $row['generation'], 'raw' => $row['evidence'], 'state' => $state];
    }

    private static function cas(array $head, array $state): bool
    {
        global $DB;
        $json = self::json($state);
        if (strlen($json) > 65536) { throw new \RuntimeException('Field capture head limit reached.'); }
        self::sql('UPDATE `' . self::TABLE . '` SET `generation`=`generation`+1,`evidence`=' . $DB->quote($json)
            . ' WHERE `computer_id`=' . $head['id'] . ' AND `field_key`=' . $DB->quote($head['key'])
            . ' AND `generation`=' . $head['generation'] . ' AND CAST(`evidence` AS BINARY)=CAST(' . $DB->quote($head['raw']) . ' AS BINARY)');
        return $DB->affectedRows() === 1;
    }

    private static function descriptors(string $class, array $fields): array
    {
        if ($class === 'Computer') { return [self::descriptorForKey('native:name'), self::descriptorForKey('native:comment')]; }
        global $DB;
        $containerId = (int) ($fields['plugin_fields_containers_id'] ?? 0);
        $descriptors = [];
        foreach (self::rows('SELECT * FROM `glpi_plugin_fields_fields` WHERE `plugin_fields_containers_id`=' . $containerId . ' LOCK IN SHARE MODE') as $field) {
            if (!in_array($field['type'], ['text', 'textarea'], true) || !empty($field['multiple'])) { continue; }
            $descriptor = self::descriptorForKey('fields:' . (int) $field['id']);
            if ($descriptor['class'] !== $class) { throw new \RuntimeException('Field capture class mismatch.'); }
            $descriptors[] = $descriptor;
        }
        return $descriptors;
    }

    private static function descriptorForKey(string $key): array
    {
        if (in_array($key, ['native:name', 'native:comment'], true)) {
            $column = substr($key, 7);
            $matches = [];
            foreach ((new \Computer())->rawSearchOptions() as $option) {
                if (is_array($option) && ($option['table'] ?? '') === 'glpi_computers' && ($option['field'] ?? '') === $column) {
                    $matches[] = (int) $option['id'];
                }
            }
            if (count($matches) !== 1 || $matches[0] < 1) { throw new \RuntimeException('Field capture native reference unavailable.'); }
            return ['key' => $key, 'column' => $column, 'table' => 'glpi_computers', 'class' => 'Computer',
                'reference' => $matches[0], 'definition' => self::hash([$key, $matches[0]])];
        }
        if (!preg_match('/^fields:([1-9][0-9]*)$/D', $key, $match) || !class_exists('PluginFieldsContainer')
            || !defined('PluginFieldsField::SEARCH_OPTION_STARTING_INDEX')) { throw new \RuntimeException('Field capture key unsupported.'); }
        // Shared current reads avoid stale RR metadata without serializing unrelated asset readers.
        $field = self::one('SELECT * FROM `glpi_plugin_fields_fields` WHERE `id`=' . (int) $match[1] . ' LOCK IN SHARE MODE');
        $container = $field ? self::one('SELECT * FROM `glpi_plugin_fields_containers` WHERE `id`=' . (int) $field['plugin_fields_containers_id'] . ' LOCK IN SHARE MODE') : null;
        if (!$container || !in_array($field['type'], ['text', 'textarea'], true) || !empty($field['multiple'])
            || !in_array('Computer', json_decode($container['itemtypes'], true) ?? [], true)
            || !preg_match('/^[a-z][a-z0-9_]*$/D', $field['name'])) { throw new \RuntimeException('Field capture definition unsupported.'); }
        $class = \PluginFieldsContainer::getClassname('Computer', $container['name']);
        if (!self::modelClass($class) || !is_callable([$class, 'getTable'])) { throw new \RuntimeException('Field capture class unavailable.'); }
        $table = $class::getTable();
        if (!preg_match('/^glpi_plugin_fields_[a-z0-9_]+$/D', $table)) { throw new \RuntimeException('Field capture table unsupported.'); }
        return ['key' => $key, 'column' => $field['name'], 'table' => $table, 'class' => $class,
            'container' => (int) $container['id'], 'reference' => \PluginFieldsField::SEARCH_OPTION_STARTING_INDEX + (int) $field['id'],
            'definition' => self::hash([(int) $field['id'], $field['type'], (int) $field['multiple'], $field['name'],
                (int) $container['id'], $class, $table, \PluginFieldsField::SEARCH_OPTION_STARTING_INDEX + (int) $field['id']])];
    }

    private static function snapshot(int $id, array $descriptor): array
    {
        $current = self::descriptorForKey($descriptor['key']);
        if ($current !== $descriptor) { throw new \RuntimeException('Field capture definition changed.'); }
        $parent = self::one('SELECT `id`,`entities_id`,`is_deleted`,`name`,`comment` FROM `glpi_computers` WHERE `id`=' . $id . ' FOR UPDATE');
        if (!$parent) { throw new \RuntimeException('Field capture asset missing.'); }
        $row = $parent;
        if ($descriptor['class'] !== 'Computer') {
            $rows = self::rows('SELECT * FROM `' . $descriptor['table'] . '` WHERE `items_id`=' . $id
                . " AND `itemtype`='Computer' AND `plugin_fields_containers_id`=" . $descriptor['container'] . ' LIMIT 2 FOR UPDATE');
            if (count($rows) !== 1) { throw new \RuntimeException('Field capture child missing or ambiguous.'); }
            $row = $rows[0];
        }
        if (!array_key_exists($descriptor['column'], $row)) { throw new \RuntimeException('Field capture value unavailable.'); }
        return ['hash' => self::valueHash($row[$descriptor['column']]),
            'row_identity' => self::rowIdentity($row, $descriptor),
            // Entity/deleted scope is authorization elsewhere, not the identity of an unchanged field.
            'meta' => self::hash([$descriptor, $id, (int) $row['id']])];
    }

    private static function rowIdentity(array $row, array $descriptor): string
    {
        $keys = $descriptor['class'] === 'Computer' ? ['id', 'entities_id', 'is_deleted']
            : ['id', 'items_id', 'itemtype', 'plugin_fields_containers_id'];
        $identity = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) { throw new \RuntimeException('Field capture loaded identity unavailable.'); }
            $identity[$key] = $key === 'itemtype' ? $row[$key] : (int) $row[$key];
        }
        return self::hash($identity);
    }

    private static function history(int $id, array $descriptor, ?int $anchorId = null): array
    {
        $rows = self::rows('SELECT * FROM `glpi_logs` WHERE ' . self::historyWhere($id, $descriptor) . ' ORDER BY `id` DESC LIMIT 1 FOR UPDATE');
        $latest = $rows === [] ? 0 : (int) $rows[0]['id'];
        $anchor = $rows[0] ?? null;
        if ($anchorId !== null && $anchorId !== $latest) {
            $anchor = self::one('SELECT * FROM `glpi_logs` WHERE ' . self::historyWhere($id, $descriptor) . ' AND `id`=' . $anchorId . ' FOR UPDATE');
        }
        return ['latest' => $latest, 'anchor' => $anchor ? self::anchor($anchor) : null];
    }

    private static function historyAfter(int $id, array $descriptor, int $watermark): array
    {
        return self::rows('SELECT * FROM `glpi_logs` WHERE ' . self::historyWhere($id, $descriptor)
            . ' AND `id`>' . $watermark . ' ORDER BY `id` LIMIT 2 FOR UPDATE');
    }

    private static function historyWhere(int $id, array $descriptor): string
    {
        return "`itemtype`='Computer' AND `items_id`=" . $id . ' AND `id_search_option`=' . $descriptor['reference'];
    }

    private static function anchor(array $row): array { ksort($row); return ['id' => (int) $row['id'], 'hash' => self::hash($row)]; }

    /** Stock Log::history removes JSON collections and truncates text; never use this hash as the full value. */
    private static function projection(mixed $old, mixed $new, bool $project = true): string
    {
        $values = [];
        foreach ([$old, $new] as $value) {
            if ($value !== null && !is_string($value)) { throw new \RuntimeException('Field capture text unsupported.'); }
            $value ??= '';
            if ($project) {
                $decoded = json_decode($value);
                if (is_array($decoded) || is_object($decoded)) { $value = ''; }
                $value = mb_substr($value, 0, 255);
            }
            $values[] = $value;
        }
        return self::hash($values);
    }

    private static function valueHash(mixed $value): string
    {
        if ($value !== null && !is_string($value)) { throw new \RuntimeException('Field capture text unsupported.'); }
        return self::hash(['type' => 'text', 'value' => $value]);
    }

    private static function modelClass(string $class): bool
    {
        return $class === 'Computer' || (bool) preg_match('/^PluginFieldsComputer[a-zA-Z0-9_]+$/D', $class);
    }

    private static function connection(): string
    {
        $row = self::one('SELECT CONNECTION_ID() AS connection_id');
        if (!ctype_digit((string) ($row['connection_id'] ?? ''))) { throw new \RuntimeException('Field capture connection unavailable.'); }
        return (string) $row['connection_id'];
    }

    private static function sameConnection(array $entry): bool
    {
        global $DB;
        return $DB === $entry['db'] && self::connection() === $entry['connection'];
    }

    private static function epoch(): int
    {
        $row = self::one('SELECT UNIX_TIMESTAMP() AS epoch');
        if (!ctype_digit((string) ($row['epoch'] ?? ''))) { throw new \RuntimeException('Field capture UTC unavailable.'); }
        return (int) $row['epoch'];
    }

    /** A savepoint probe detects START TRANSACTION even when @@autocommit remains 1. It never ends it. */
    private static function idle(): bool
    {
        global $DB;
        $row = self::one('SELECT @@SESSION.autocommit AS enabled');
        if (!in_array($row['enabled'] ?? null, [1, '1'], true)) { return false; }
        $name = 'as20_capture_' . bin2hex(random_bytes(12));
        if ($DB->doQuery('SAVEPOINT ' . $name) !== true) { return false; }
        try { $released = $DB->doQuery('RELEASE SAVEPOINT ' . $name); }
        catch (\Throwable) { $released = false; }
        return $released === false && $DB->errno() === 1305;
    }

    private static function json(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private static function hash(mixed $value): string { return hash('sha256', self::json($value)); }
    private static function one(string $sql): ?array
    {
        global $DB;
        $row = $DB->fetchAssoc(self::sql($sql));
        return is_array($row) ? $row : null;
    }
    private static function rows(string $sql): array
    {
        global $DB;
        $result = self::sql($sql);
        $rows = [];
        while ($row = $DB->fetchAssoc($result)) { $rows[] = $row; }
        return $rows;
    }
    private static function sql(string $sql): mixed
    {
        global $DB;
        $result = $DB->doQuery($sql);
        if ($result === false) { throw new \RuntimeException('Field capture storage unavailable.'); }
        return $result;
    }
}
