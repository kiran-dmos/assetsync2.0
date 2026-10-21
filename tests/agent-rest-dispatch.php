<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20 {
    function curl_init(string $url): object { return (object) ['url' => $url, 'options' => []]; }
    function curl_setopt_array(object $handle, array $options): bool { $handle->options = $options; return true; }
    function curl_setopt(object $handle, int $option, mixed $value): bool { $handle->options[$option] = $value; return true; }
    function curl_exec(object $handle): bool { return \RestDispatchWire::execute($handle); }
    function curl_getinfo(object $handle, int $option): int { return $handle->status; }
    function curl_close(object $handle): void { \RestDispatchWire::$closed++; }
}

namespace {
    ob_start();
    require __DIR__ . '/agent-tasks.php';
    ob_end_clean();

    use GlpiPlugin\Assetsync20\AgentActivity;
    use GlpiPlugin\Assetsync20\AgentInbox;
    use GlpiPlugin\Assetsync20\AgentTask;
    use GlpiPlugin\Assetsync20\AssetSyncQueue;
    use GlpiPlugin\Assetsync20\GlpiBConnection;
    use GlpiPlugin\Assetsync20\RestSession;
    use GlpiPlugin\Assetsync20\RestWork;

    final class RestDispatchWire
    {
        public static array $replies = [];
        public static array $calls = [];
        public static int $closed = 0;
        public static ?array $login = null;
        public static function execute(object $handle): bool
        {
            $path = substr($handle->url, strpos($handle->url, '/apirest.php/') + strlen('/apirest.php/'));
            $method = $handle->options[CURLOPT_CUSTOMREQUEST];
            self::$calls[] = ['method' => $method, 'path' => $path, 'options' => $handle->options];
            if ($path === 'initSession') { $reply = self::$login ?? ['status' => 200, 'body' => ['session_token' => 'PRIVATE-SESSION']]; }
            elseif ($path === 'killSession') { $reply = ['status' => 200, 'body' => []]; }
            else {
                $reply = array_shift(self::$replies);
                if ($reply instanceof Closure) { $reply = $reply($method, $path, $handle->options); }
                if (!is_array($reply)) { throw new LogicException('Unexpected REST request'); }
            }
            $handle->status = $reply['status'];
            if ($reply['status'] === 0) { return false; }
            $body = is_string($reply['body']) ? $reply['body'] : json_encode($reply['body'], JSON_THROW_ON_ERROR);
            return ($handle->options[CURLOPT_WRITEFUNCTION])($handle, $body) === strlen($body);
        }
        public static function reset(): void { self::$replies = []; self::$calls = []; self::$closed = 0; self::$login = null; }
    }

    /** Only native transaction/lock probes are simulated; all task SQL runs in SQLite. */
    class RestDispatchDB
    {
        public function __construct(public AgentSqlite $sql) {}
        public function tableExists(string $table): bool { return $this->sql->tableExists($table); }
        public function quote(string $value): string { return $this->sql->quote($value); }
        public function fetchAssoc(PDOStatement $result): ?array { return $this->sql->fetchAssoc($result); }
        public function affectedRows(): int { return $this->sql->affectedRows(); }
        public function request(array $query): ArrayIterator { return $this->sql->request($query); }
        public function firstRow(string $table, array $where): ?array { return $this->sql->firstRow($table, $where); }
        public function update(string $table, array $fields, array $where): bool { return $this->sql->update($table, $fields, $where); }
        public function insert(string $table, array $fields): bool { return $this->sql->insert($table, $fields); }
        public function fieldExists(string $table, string $field): bool { return $this->sql->fieldExists($table, $field); }
        public function errno(): int { return 1305; }
        public function doQuery(string $sql): mixed
        {
            if ($sql === 'SELECT @@SESSION.autocommit AS enabled, CONNECTION_ID() AS connection_id') {
                return $this->sql->pdo->query('SELECT 1 AS enabled, 17 AS connection_id');
            }
            if ($sql === 'SELECT CONNECTION_ID() AS connection_id') { return $this->sql->pdo->query('SELECT 17 AS connection_id'); }
            if (str_starts_with($sql, 'SAVEPOINT as20_wake_')) { return true; }
            if (str_starts_with($sql, 'RELEASE SAVEPOINT as20_wake_')) { return false; }
            if (str_starts_with($sql, 'SELECT COALESCE(IS_USED_LOCK(')) { return $this->sql->pdo->query('SELECT 0 AS owned'); }
            return $this->sql->doQuery($sql);
        }
    }

    function restSetup(bool $cached = true, bool $expired = false): array
    {
        global $DB;
        taskSetup();
        $pair = AgentTask::pair('b');
        $connection = GlpiBConnection::find('b');
        GlpiBConnection::save(array_replace($connection, ['agent_rest_pair' => $pair['id'],
            'agent_rest_url' => 'https://PRIVATE-URL.invalid/apirest.php', 'agent_app_token' => 'PRIVATE-APP', 'agent_user_token' => 'PRIVATE-USER']));
        AssetSyncQueue::notify('Computer', 101, 'b');
        $job = AssetSyncQueue::claimDue(1)[0];
        RestWork::process($job);
        $task = AgentTask::outstanding('b', $pair);
        $body = json_decode($task['body'], true, 32, JSON_THROW_ON_ERROR);
        agentCheck($body['version'] === 7 && queue()['status'] === 'waiting_agent', 'Production process publishes and parks v7 work.');
        if ($expired) {
            $body['expires'] = time() - 1;
            $DB->update(AgentTask::TABLE, ['body' => json_encode($body), 'hash' => AgentTask::hash($body), 'expires' => $body['expires']], ['id' => $task['id']]);
            $task = AgentTask::outstanding('b', $pair);
        }
        if ($cached) {
            $connection = GlpiBConnection::find('b');
            $key = hash('sha256', $connection['id'] . $connection['agent_rest_url'] . $body['settings_hash']);
            Config::setConfigurationValues(GlpiBConnection::CONTEXT, ['rest_settings_receipts' => json_encode([$key => 9])]);
        }
        $DB = new RestDispatchDB($DB);
        RestDispatchWire::reset();
        return [$task, $body];
    }

    function restRecord(array $task, string $state = 'accepted', array $result = []): array
    {
        return ['id' => 31, 'work_id' => $task['id'], 'hash' => $task['hash'], 'body' => $task['body'],
            'state' => $state, 'result' => $result === [] ? '{}' : json_encode($result)];
    }

    function restDispatch(array $task, array $replies): array
    {
        global $DB;
        $saved = AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE `id` = ' . $DB->quote($task['id']));
        $context = json_decode($saved['context'], true);
        $context['rest_next'] = 0;
        $DB->update(AgentTask::TABLE, ['context' => json_encode($context)], ['id' => $task['id']]);
        RestDispatchWire::$replies = $replies;
        RestWork::sendPending(hrtime(true) + 20_000_000_000);
        agentCheck(RestDispatchWire::$replies === [], 'All expected REST responses consumed.');
        return AgentInbox::one('SELECT * FROM `' . AgentTask::TABLE . '` WHERE `id` = ' . $DB->quote($task['id']));
    }

    function restLabel(array $task): string
    {
        ob_start();
        AgentActivity::renderTasks([1], static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES), 'b');
        $html = ob_get_clean();
        agentCheck(str_contains($html, $task['id']), 'Activity renders the visible REST task.');
        return $html;
    }

    function restImmutable(array $before, array $after): void
    {
        foreach (['id', 'hash', 'body', 'created_at', 'expires'] as $key) {
            agentCheck($before[$key] === $after[$key], 'Immutable REST evidence preserved: ' . $key);
        }
        agentCheck($after['state'] === 'accepted' && queue()['agent_completed'] === 0, 'Dispatch never finalizes A or advances completion.');
    }

    [$task, $body] = restSetup();
    $record = restRecord($task);
    $saved = restDispatch($task, [['status' => 400, 'body' => ['ERROR_GLPI_ADD', 'PRIVATE payload token https://PRIVATE-URL.invalid']],
        ['status' => 200, 'body' => [$record]], ['status' => 200, 'body' => $record]]);
    $context = json_decode($saved['context'], true);
    agentCheck($context['rest_error']['phase'] === 'work_post' && $context['rest_error']['status'] === 400
        && $context['rest_error']['error'] === 'ERROR_GLPI_ADD' && $context['rest_state'] === 'accepted', 'POST failure survives successful accepted GET.');
    agentCheck($saved['result'] === '{}' && queue()['status'] === 'waiting_agent', 'Denial after acceptance stays pending without a result.');
    $label = restLabel($saved);
    agentCheck(str_contains($label, 'REST accepted on B; completion pending') && str_contains($label, 'record add failed (HTTP 400)')
        && !str_contains($label, 'permission') && !str_contains($label, 'REST verified'), 'Activity explains acceptance and does not invent a permission cause.');
    agentCheck(!str_contains($saved['context'] . $label, 'PRIVATE') && $context['rest_next'] >= time()
        && $context['rest_expires'] === $body['expires'] && $context['rest_until'] === 0, 'Only safe metadata persists with retry/expiry and released reservation.');
    restImmutable($task, $saved);
    $snapshot = ['itemtype' => 'Computer', 'items_id' => $body['items_id'], 'entity_id' => $body['entity_id'], 'pair_id' => $body['pair_id'],
        'serial' => $body['identity']['serial'], 'otherserial' => $body['identity']['otherserial'] ?? '', 'observed_at' => time(),
        'fields' => ['name' => ['value' => $body['name']]]];
    $result = ['outcome' => 'verified', 'changed' => ['name' => true], 'snapshot' => $snapshot];
    $terminal = restRecord($task, 'verified', $result);
    $saved = restDispatch($task, [['status' => 200, 'body' => [$record]], ['status' => 200, 'body' => $record],
        ['status' => 201, 'body' => ['id' => 31]], ['status' => 200, 'body' => $terminal]]);
    restImmutable($task, $saved);
    agentCheck(json_decode($saved['result'], true) === $result && queue()['status'] === 'pending', 'Restored service continues the same work and wakes A finalization.');
    $posts = array_values(array_filter(RestDispatchWire::$calls, static fn ($call): bool => $call['method'] === 'POST'));
    agentCheck(count($posts) === 2 && $posts[0]['options'][CURLOPT_POSTFIELDS] === $posts[1]['options'][CURLOPT_POSTFIELDS], 'Deny/restore resends exactly the same ID/body/hash.');
    agentCheck(str_contains(restLabel($saved), 'REST result received; A finalization pending'), 'Received proof is distinct from completed A finalization.');

    [$task] = restSetup();
    $saved = restDispatch($task, [['status' => 0, 'body' => []], ['status' => 503, 'body' => ['PRIVATE-ERROR', 'PRIVATE']]]);
    agentCheck(json_decode($saved['context'], true)['rest_state'] === 'unknown' && $saved['result'] === '{}', 'Ambiguous POST and failed GET retain unknown outcome.');
    $saved = restDispatch($task, [['status' => 503, 'body' => ['error' => 'PRIVATE']]]);
    $posts = array_filter(RestDispatchWire::$calls, static fn ($call): bool => $call['method'] === 'POST');
    agentCheck(count($posts) === 1 && $saved['result'] === '{}' && queue()['status'] === 'waiting_agent', 'Failed subsequent GET never permits another ambiguous POST.');
    agentCheck(str_contains(restLabel($saved), 'REST outcome unknown; awaiting reconciliation') && !str_contains($saved['context'], 'PRIVATE'), 'Unknown state remains visible and redacted.');
    restImmutable($task, $saved);

    [$task, $body] = restSetup(true, true);
    $saved = restDispatch($task, [['status' => 200, 'body' => []]]);
    agentCheck(json_decode($saved['result'], true)['outcome'] === 'stale' && json_decode($saved['context'], true)['rest_state'] === 'expired'
        && !array_filter(RestDispatchWire::$calls, static fn ($call): bool => $call['method'] === 'POST'), 'Expired absent work becomes stale without a POST.');
    agentCheck(str_contains(restLabel($saved), 'REST expired or stale; retained for inspection'), 'Expiry never appears verified.');
    restImmutable($task, $saved);

    [$task] = restSetup();
    $record = restRecord($task, 'verified');
    $saved = restDispatch($task, [['status' => 201, 'body' => ['id' => 31]], ['status' => 200, 'body' => $record]]);
    agentCheck($saved['result'] === '{}' && !str_contains(restLabel($saved), 'REST verified'), 'Remote state alone never proves verification.');
    $record['result'] = json_encode(['outcome' => 'verified', 'changed' => [], 'snapshot' => ['name' => 'PRIVATE']]);
    $saved = restDispatch($task, [['status' => 200, 'body' => [$record]], ['status' => 200, 'body' => $record]]);
    agentCheck($saved['result'] === '{}' && json_decode($saved['context'], true)['rest_error']['error'] === 'invalid_result'
        && !str_contains($saved['context'] . restLabel($saved), 'PRIVATE'), 'Invalid result never persists as proof or diagnostic payload.');

    [$task] = restSetup(false);
    $saved = restDispatch($task, [['status' => 400, 'body' => ['error' => 'permission PRIVATE-APP']]]);
    $context = json_decode($saved['context'], true);
    agentCheck($context['rest_error']['phase'] === 'settings_post' && $context['rest_error']['error'] === 'http_error'
        && !$context['rest_sent'], 'Generic settings 400 remains a generic HTTP error before work send.');
    agentCheck(!str_contains(restLabel($saved), 'permission'), 'Generic 400 text cannot create a permission diagnosis.');

    [$task] = restSetup();
    RestDispatchWire::$login = ['status' => 400, 'body' => ['ERROR_SESSION_TOKEN_INVALID', 'PRIVATE-SESSION']];
    $saved = restDispatch($task, []);
    agentCheck(json_decode($saved['context'], true)['rest_error']['phase'] === 'init_session'
        && json_decode($saved['context'], true)['rest_error']['error'] === 'ERROR_SESSION_TOKEN_INVALID'
        && !array_filter(RestDispatchWire::$calls, static fn ($call): bool => $call['method'] === 'POST'), 'Native session failure is attributed to initialization with no work POST.');

    RestDispatchWire::reset();
    $connection = GlpiBConnection::find('b');
    $client = new RestSession($connection, hrtime(true) + 10_000_000_000);
    RestDispatchWire::$replies = [['status' => 200, 'body' => ['id' => 31]]];
    agentCheck($client->request('GET', 'resource/31') === ['status' => 200, 'body' => ['id' => 31]], 'Standalone request API retains exact status/body shape.');
    $diagnostic = $client->diagnostic();
    $client->close();
    $client->close();
    agentCheck($client->diagnostic() === $diagnostic && count(RestDispatchWire::$calls) === 3 && RestDispatchWire::$closed === 3,
        'Standalone cleanup is idempotent and does not replace the request diagnostic.');
    foreach (RestDispatchWire::$calls as $call) {
        $options = $call['options'];
        agentCheck($options[CURLOPT_SSL_VERIFYPEER] && $options[CURLOPT_SSL_VERIFYHOST] === 2
            && !$options[CURLOPT_FOLLOWLOCATION] && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS
            && $options[CURLOPT_REDIR_PROTOCOLS] === CURLPROTO_HTTPS, 'Strict HTTPS and no redirects retained.');
    }
    agentCheck(RestDispatchWire::$calls[2]['options'][CURLOPT_TIMEOUT_MS] <= 500, 'Cleanup stays bounded.');
    $client = new RestSession($connection, hrtime(true));
    agentCheck($client->request('GET', 'resource') === ['status' => 0, 'body' => []]
        && $client->diagnostic()['error'] === 'budget_exhausted', 'Expired standalone budget reports safe metadata.');
    echo "REST dispatch diagnostics passed: deny/restore same-ID continuation, unknown POST/GET, expiry, redaction, Activity and standalone compatibility (mocked HTTP).\n";
}
