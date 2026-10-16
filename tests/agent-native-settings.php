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
    setupCheck($saved['wake_token'] === '' && $saved['agent_auth'] === 'glpi_session', 'Native A configuration needs no legacy wake token.');
    setupCheck($saved['agent_app_token'] === $peer['agent_app_token'] && $saved['agent_user_token'] === $peer['agent_user_token'],
        'Native A credentials round trip through encrypted storage.');
    $raw = json_encode(Config::$values[GlpiBConnection::CONTEXT]);
    setupCheck(!str_contains($raw, 'native-app-secret') && !str_contains($raw, 'native-user-secret'), 'A storage contains no plaintext native secrets.');
    $identity = AgentInbox::identity($saved);
    GlpiBConnection::save(array_replace($input, ['agent_auth' => 'legacy_bearer', 'wake_enabled' => false,
        'agent_app_token' => '', 'agent_user_token' => '']));
    $rollback = GlpiBConnection::find('native');
    setupCheck($rollback['agent_app_token'] === $peer['agent_app_token'] && AgentInbox::identity($rollback) === $identity,
        'Explicit rollback preserves credentials and stable asset binding identity.');

    Config::$values[Settings::CONTEXT] = [];
    $b = array_replace($peer, ['agent_api_url' => 'https://a.invalid/plugins/assetsync20/front/agent-api.php',
        'mode' => 'agent', 'connection_id' => 'native', 'registration' => str_repeat('b', 32), 'generation' => str_repeat('c', 32),
        'service_user' => '7', 'service_profile' => '4', 'service_password' => 'local-password']);
    Settings::save($b);
    $savedB = Settings::load();
    setupCheck($savedB['token'] === '' && $savedB['endpoint'] === '' && Settings::transportReady($savedB),
        'Fresh B native setup requires no custom token or legacy endpoint.');
    setupCheck(!str_contains(json_encode(Config::$values[Settings::CONTEXT]), 'native-app-secret')
        && !str_contains(json_encode(Config::$values[Settings::CONTEXT]), 'local-password'), 'B stores native and service credentials encrypted.');
    Settings::install();
    setupCheck(Settings::configureWake(true) === null && Settings::load()['wake_enabled'] === '1'
        && Settings::load()['wake_token_hash'] === '', 'Native wake enables without generating a custom bearer key.');

    $legacyToken = str_repeat('d', 64);
    Config::setConfigurationValues(Settings::CONTEXT, ['token' => (new GLPIKey())->encrypt($legacyToken),
        'endpoint' => 'https://a.invalid/plugins/assetsync20/front/agent-notify.php']);
    Settings::save($b + ['token' => '', 'endpoint' => '']);
    setupCheck(Settings::load()['token'] === $legacyToken && Settings::load()['endpoint'] !== '', 'Existing legacy credentials and URL survive native setup.');
    Settings::save(array_replace($b, ['agent_auth' => 'legacy_bearer', 'endpoint' => Settings::load()['endpoint']]));
    setupCheck(Settings::load()['agent_auth'] === 'legacy_bearer' && Settings::load()['token'] === $legacyToken, 'Explicit B rollback is reversible.');
    foreach (['a', 'b'] as $side) {
        Session::$canUpdate = true;
        $html = setupRender($side);
        $local = $side === 'a' ? 'A' : 'B';
        $remote = $side === 'a' ? 'B' : 'A';
        foreach (['GLPI native session', 'GLPI ' . $remote . ' Agent API URL', 'GLPI ' . $remote . ' app token',
            'GLPI ' . $remote . ' user API token', 'GLPI ' . $local . ' user used by ' . $remote,
            'GLPI ' . $local . ' API client used by ' . $remote, 'GLPI ' . $local . ' exact entity grant used by ' . $remote,
            $side === 'a' ? 'REST asset-mode user token' : 'Legacy registration bearer token'] as $label) {
            setupCheck(str_contains($html, $label), 'Setup identifies credential ownership: ' . $label);
        }
        setupCheck(!str_contains($html, 'native-app-secret') && !str_contains($html, 'native-user-secret'),
            'Setup never renders saved native tokens.');
    }
    ob_end_clean();
    echo "Native settings tests passed ({$checks} checks including setup prerequisites; offline GLPI doubles).\n";
}

