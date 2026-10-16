<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** Admission shared by the two stock REST resources; core still authenticates the session and IP. */
final class RestPeer
{
    public static function settings(): array
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE
            || !in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)
            || !in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)
            || !empty($_SERVER['HTTP_COOKIE']) || !empty($_COOKIE)
            || !empty($_SERVER['HTTP_AUTHORIZATION']) || empty($_SERVER['HTTP_SESSION_TOKEN'])
            || !preg_match('#/apirest\.php/GlpiPlugin\\\\Assetsync20agent\\\\Rest(Settings|Work)(/\d+)?$#D',
                rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)))) {
            throw new \RuntimeException('A closed, native stock REST session is required.');
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 49152) { throw new \RuntimeException('REST body exceeds limit.'); }
        $json = (string) file_get_contents('php://input', false, null, 0, 49153);
        if (strlen($json) > 49152) { throw new \RuntimeException('REST body exceeds limit.'); }
        $raw = $json === '' ? null : json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && (!is_array($raw) || !is_array($raw['input'] ?? null) || array_is_list($raw['input'])
                || array_diff(array_keys($raw), ['input', 'session_write']))) { throw new \RuntimeException('One REST input object is required.'); }
        if (isset($_GET['range']) && (!is_string($_GET['range']) || !preg_match('/^([0-9]+)-([0-9]+)$/D', $_GET['range'], $range)
            || (int) $range[2] < (int) $range[1] || (int) $range[2] - (int) $range[1] >= 10)) { throw new \RuntimeException('REST page exceeds limit.'); }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && !preg_match('#/\d+$#D', (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))
            && !isset($_GET['range'])) { throw new \RuntimeException('An explicit bounded REST range is required.'); }
        if ((isset($_GET['session_write']) && !in_array($_GET['session_write'], ['0', 0], true))
            || (is_array($raw) && array_key_exists('session_write', $raw) && $raw['session_write'] !== false)) {
            throw new \RuntimeException('session_write must be false.');
        }
        $settings = Settings::load();
        if (($settings['mode'] ?? '') !== 'agent') { throw new \RuntimeException('Native binding unavailable.'); }
        self::policy($settings);
        if ((int) \Session::getLoginUserID() !== (int) $settings['agent_peer_user']
            || \Session::isCron() || \Session::isInventory() || \Session::isRightChecksDisabled()
            || (new \GLPIKey())->decrypt(base64_decode((string) $_SERVER['HTTP_SESSION_TOKEN'], true) ?: '') !== session_id()) {
            throw new \RuntimeException('Native REST session mismatch.');
        }
        \Session::initEntityProfiles((int) $settings['agent_peer_user']);
        \Session::changeProfile((int) $settings['agent_peer_profile']);
        self::entity($settings, (int) $settings['agent_peer_entity']);
        return $settings;
    }

    public static function policy(array $settings): void
    {
        AgentApi::validatePeer($settings);
        $token = (string) ($_SERVER['HTTP_APP_TOKEN'] ?? '');
        $matches = [];
        global $DB;
        foreach ($DB->request(['FROM' => 'glpi_apiclients', 'WHERE' => ['is_active' => 1]]) as $client) {
            $plain = (new \GLPIKey())->decrypt($client['app_token'] ?? '') ?? '';
            if ($token !== '' && $plain !== '' && hash_equals($plain, $token)) { $matches[] = (int) $client['id']; }
        }
        if ($matches !== [(int) $settings['agent_peer_api_client']]) {
            throw new \RuntimeException('Native REST peer or API client mismatch.');
        }
    }

    public static function binding(array $settings): string
    {
        $binding = [];
        foreach (['mode', 'installation', 'registration', 'generation', 'connection_id', 'service_user', 'service_profile',
            'service_name', 'agent_peer_user', 'agent_peer_api_client', 'agent_peer_profile', 'agent_peer_entity'] as $key) {
            if (!isset($settings[$key]) || (string) $settings[$key] === '') { throw new \RuntimeException('REST binding is incomplete.'); }
            $binding[$key] = (string) $settings[$key];
        }
        return TaskExecutor::hash($binding);
    }

    public static function work(array $body): array
    {
        $settings = self::settings();
        foreach (['installation', 'registration', 'generation', 'connection_id'] as $key) {
            if (($body[$key] ?? null) !== ($settings[$key] ?? null)) { throw new \RuntimeException('REST work binding changed.'); }
        }
        self::entity($settings, $body['entity_id']);
        $entity = $body['entity_id'];
        $item = new \Computer();
        if (!$item->getFromDB($body['items_id']) || !empty($item->fields['is_deleted']) || !empty($item->fields['is_template'])
            || (int) $item->fields['entities_id'] !== $entity || !$item->can($body['items_id'], READ)
            || array_intersect_key(TaskExecutor::identity($item->fields), $body['identity']) !== $body['identity']) { throw new \RuntimeException('REST work target denied.'); }
        return $settings;
    }

    public static function entity(array $settings, int $entity): void
    {
        self::grant($settings, $entity);
        if (!\Session::changeActiveEntities($entity, false)) { throw new \RuntimeException('REST work entity activation denied.'); }
    }

    public static function grant(array $settings, int $entity): void
    {
        $grant = new \Profile_User();
        $profile = new \Profile();
        if (!$grant->getFromDBByCrit(['users_id' => (int) $settings['agent_peer_user'],
            'profiles_id' => (int) $settings['agent_peer_profile'], 'entities_id' => $entity, 'is_recursive' => 0])
            || !$profile->getFromDB((int) $settings['agent_peer_profile']) || ((int) ($profile->fields['computer'] ?? 0) & READ) !== READ) {
            throw new \RuntimeException('REST work exact grant or native READ denied.');
        }
    }

    /** SAVEPOINT has no lifetime in autocommit; never commit a caller-owned transaction. */
    public static function idle(): bool
    {
        global $DB;
        $row = Outbox::one('SELECT @@SESSION.autocommit AS enabled, CONNECTION_ID() AS id');
        if (!in_array($row['enabled'] ?? null, [1, '1'], true)) { return false; }
        $savepoint = 'as20_rest_' . bin2hex(random_bytes(8));
        Outbox::sql('SAVEPOINT ' . $savepoint);
        try { $released = $DB->doQuery('RELEASE SAVEPOINT ' . $savepoint); }
        catch (\Throwable) { $released = false; }
        return $released === false && $DB->errno() === 1305
            && (string) (Outbox::one('SELECT CONNECTION_ID() AS id')['id'] ?? '') === (string) $row['id'];
    }

    public static function canView(): bool
    {
        try { self::settings(); return true; } catch (\Throwable) { return false; }
    }
}
