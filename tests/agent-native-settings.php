<?php

declare(strict_types=1);

namespace Glpi\Api { class APIRest {} }
namespace {
    ob_start();
    require __DIR__ . '/agent-wake-setup.php';

    final class Profile {
        public array $fields = ['interface' => 'central'];
        public function getFromDB(int $id): bool { return $id === 4; }
    }
    final class Profile_User {
        public function getFromDBByCrit(array $criteria): bool { return $criteria === ['users_id' => 7, 'profiles_id' => 4, 'entities_id' => 2, 'is_recursive' => 0]; }
    }
    final class APIClient {
        public array $fields = ['is_active' => 1, 'app_token' => 'encrypted'];
        public function getFromDB(int $id): bool { return $id === 9; }
    }

    use GlpiPlugin\Assetsync20\GlpiBConnection;
    use GlpiPlugin\Assetsync20\AgentInbox;
    use GlpiPlugin\Assetsync20agent\Settings;

    $scopeDb = new PDO('sqlite::memory:');
    $DB->queryResult = static fn (string $sql) => str_contains($sql, 'glpi_profiles_users')
        ? $scopeDb->query('SELECT 2 AS entities_id') : [];
    $peer = ['agent_auth' => 'glpi_session', 'agent_api_url' => 'https://b.invalid/plugins/assetsync20agent/front/agent-api.php',
        'agent_app_token' => 'native-app-secret', 'agent_user_token' => 'native-user-secret',
        'agent_peer_user' => '7', 'agent_peer_api_client' => '9', 'agent_peer_profile' => '4', 'agent_peer_entity' => '2'];
    $input = $peer + ['id' => 'native', 'mode' => 'agent', 'active' => true, 'name' => 'Native peer', 'base_url' => 'http://browser.invalid',
        'wake_enabled' => true];
    GlpiBConnection::save($input);
    $saved = GlpiBConnection::find('native');
    setupCheck(!isset($saved['wake_token']) && $saved['agent_auth'] === 'glpi_session', 'Native A configuration needs no legacy wake token.');
    setupCheck($saved['agent_app_token'] === $peer['agent_app_token'] && $saved['agent_user_token'] === $peer['agent_user_token'],
        'Native A credentials round trip through encrypted storage.');
    $raw = json_encode(Config::$values[GlpiBConnection::CONTEXT]);
    setupCheck(!str_contains($raw, 'native-app-secret') && !str_contains($raw, 'native-user-secret'), 'A storage contains no plaintext native secrets.');
    $identity = AgentInbox::identity($saved);
    $ordinary = ['id' => 'ordinary', 'mode' => 'rest', 'active' => true, 'base_url' => 'https://rest.invalid',
        'app_token' => 'ordinary-app', 'user_token' => 'ordinary-user'];
    $entries = json_decode(Config::$values[GlpiBConnection::CONTEXT]['glpib_connections'], true);
    $entries[] = $ordinary + ['wake_url' => 'retired', 'wake_token' => 'obsolete-rest-entry-key'];
    $nativeIndex = array_search('native', array_column($entries, 'id'), true);
    $entries[$nativeIndex]['wake_url'] = 'https://retired.invalid/front/agent-wake.php';
    $entries[$nativeIndex]['wake_token'] = 'obsolete-custom-secret';
    Config::$values[GlpiBConnection::CONTEXT]['glpib_connections'] = json_encode($entries);
    unset(Config::$values[GlpiBConnection::CONTEXT]['native_only_upgrade']);
    $evidence = $DB->tables;
    GlpiBConnection::upgradeNativeOnly();
    $migrated = json_decode(Config::$values[GlpiBConnection::CONTEXT]['glpib_connections'], true);
    setupCheck(end($migrated) === $ordinary && !isset($migrated[$nativeIndex]['wake_token'], $migrated[$nativeIndex]['wake_url'])
        && !$migrated[$nativeIndex]['wake_enabled'], 'A upgrade removes custom credentials, disables wake and leaves ordinary REST exact.');
    setupCheck(AgentInbox::identity(GlpiBConnection::find('native')) === $identity && $DB->tables === $evidence,
        'A upgrade preserves asset link identity and all task/journal/evidence rows.');
    GlpiBConnection::save(array_replace($input, ['wake_enabled' => true]));
    $once = Config::$values;
    GlpiBConnection::upgradeNativeOnly();
    setupCheck(Config::$values === $once, 'A upgrade is idempotent and does not disable explicitly re-enabled native wake.');
    GlpiBConnection::save($ordinary);
    setupCheck(GlpiBConnection::find('ordinary')['user_token'] === 'ordinary-user', 'Ordinary REST saves require no native configuration.');

    Config::$values[Settings::CONTEXT] = [];
    $DB = new class($DB, $scopeDb) {
        public string $dbdefault = 'native-settings-test';
        public function __construct(private object $base, private PDO $pdo) {}
        public function __get(string $key): mixed { return $this->base->$key; }
        public function __call(string $name, array $args): mixed { return $this->base->$name(...$args); }
        public function errno(): int { return 1305; }
        public function doQuery(string $sql): mixed {
            if ($sql === 'SELECT @@SESSION.autocommit AS enabled, CONNECTION_ID() AS id') { return $this->pdo->query('SELECT 1 AS enabled, 17 AS id'); }
            if ($sql === 'SELECT CONNECTION_ID() AS id') { return $this->pdo->query('SELECT 17 AS id'); }
            if (str_starts_with($sql, 'SAVEPOINT ')) { return true; }
            if (str_starts_with($sql, 'RELEASE SAVEPOINT ')) { return false; }
            if (str_starts_with($sql, 'SELECT COALESCE(IS_USED_LOCK(')) { return $this->pdo->query('SELECT 0 AS owned'); }
            if (str_starts_with($sql, 'SELECT GET_LOCK(')) { return $this->pdo->query('SELECT 1 AS acquired'); }
            if (str_starts_with($sql, 'SELECT RELEASE_LOCK(')) { return $this->pdo->query('SELECT 1 AS released'); }
            if (in_array($sql, ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)) { return true; }
            return $this->base->doQuery($sql);
        }
    };
    $b = array_replace($peer, ['agent_api_url' => 'https://a.invalid/plugins/assetsync20/front/agent-api.php',
        'mode' => 'agent', 'connection_id' => 'native', 'registration' => str_repeat('b', 32), 'generation' => str_repeat('c', 32),
        'service_user' => '7', 'service_profile' => '4', 'service_password' => 'local-password']);
    Settings::save($b);
    $savedB = Settings::load();
    setupCheck(!isset($savedB['token'], $savedB['endpoint']) && Settings::transportReady($savedB),
        'Fresh B native setup requires no custom token or legacy endpoint.');
    setupCheck(!str_contains(json_encode(Config::$values[Settings::CONTEXT]), 'native-app-secret')
        && !str_contains(json_encode(Config::$values[Settings::CONTEXT]), 'local-password'), 'B stores native and service credentials encrypted.');
    Config::setConfigurationValues(Settings::CONTEXT, ['installation' => str_repeat('a', 32),
        'token' => 'obsolete-custom-secret', 'endpoint' => 'https://retired.invalid/front/agent-notify.php',
        'wake_token_hash' => str_repeat('d', 64), 'wake_enabled' => '1', 'wake_binding' => 'old-derived-binding']);
    $beforeIds = array_intersect_key(Settings::load(), array_flip(['installation', 'registration', 'generation', 'connection_id']));
    $fingerprint = \GlpiPlugin\Assetsync20agent\AgentSession::fingerprint(Settings::load());
    $derived = Settings::wakeBinding(Settings::load());
    Settings::install();
    $raw = Config::$values[Settings::CONTEXT];
    setupCheck(!isset($raw['token'], $raw['endpoint'], $raw['wake_token_hash'])
        && $raw['wake_enabled'] === '0' && $raw['wake_binding'] === '', 'B upgrade deletes obsolete credentials and revokes derived wake admission.');
    setupCheck(array_intersect_key(Settings::load(), $beforeIds) === $beforeIds && $DB->tables === $evidence,
        'B upgrade preserves installation/binding IDs and all evidence rows.');
    setupCheck(\GlpiPlugin\Assetsync20agent\AgentSession::fingerprint(Settings::load()) === $fingerprint
        && Settings::wakeBinding(Settings::load()) === $derived, 'Configured native fingerprint remains stable with the constant discriminator.');
    Settings::configureWake(true);
    $enabled = Config::$values;
    Settings::install();
    setupCheck(Config::$values === $enabled && Settings::load()['wake_enabled'] === '1', 'B upgrade is idempotent after explicit wake re-enable.');
    foreach (['a', 'b'] as $side) {
        Session::$canUpdate = true;
        $html = setupRender($side);
        setupCheck(!str_contains($html, 'name="agent_auth"') && !str_contains($html, 'legacy_bearer'), 'No selectable custom authentication remains.');
        $local = $side === 'a' ? 'A' : 'B';
        $remote = $side === 'a' ? 'B' : 'A';
        foreach (['GLPI ' . $remote . ' Agent API URL', 'GLPI ' . $remote . ' app token',
            'GLPI ' . $remote . ' user API token', 'GLPI ' . $local . ' user used by ' . $remote,
            'GLPI ' . $local . ' API client used by ' . $remote, 'GLPI ' . $local . ' exact entity grant used by ' . $remote,
            $side === 'a' ? 'REST asset-mode user token' : 'Service password'] as $label) {
            setupCheck(str_contains($html, $label), 'Setup identifies credential ownership: ' . $label);
        }
        setupCheck(!str_contains($html, 'native-app-secret') && !str_contains($html, 'native-user-secret'),
            'Setup never renders saved native tokens.');
    }
    ob_end_clean();
    echo "Native settings tests passed ({$checks} checks including setup prerequisites; offline GLPI doubles).\n";
}

