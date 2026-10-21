<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

final class Settings
{
    public const CONTEXT = 'plugin:assetsync20agent';

    public static function load(): array
    {
        $settings = \Config::getConfigurationValues(self::CONTEXT);
        unset($settings['token'], $settings['endpoint'], $settings['wake_token_hash']);
        $settings['agent_auth'] = 'glpi_session';
        $settings['transport'] = $settings['transport'] ?? 'adapter';
        $settings['service_password'] = (new \GLPIKey())->decrypt($settings['service_password'] ?? '') ?? '';
        foreach (['agent_app_token', 'agent_user_token'] as $key) {
            $settings[$key] = (new \GLPIKey())->decrypt($settings[$key] ?? '') ?? '';
        }
        return $settings;
    }

    public static function transportReady(array $settings): bool
    {
        return ($settings['mode'] ?? '') === 'agent'
            && ($settings['transport'] ?? 'adapter') === 'adapter'
            && AgentSession::validUrl($settings['agent_api_url'] ?? '')
            && !empty($settings['agent_app_token']) && !empty($settings['agent_user_token']);
    }

    public static function save(array $input): void
    {
        self::withWorkerLock(static function () use ($input): void {
            global $DB;
            Outbox::sql('START TRANSACTION');
            try { self::saveLocked($input); Outbox::sql('COMMIT'); }
            catch (\Throwable $error) { $DB->doQuery('ROLLBACK'); throw $error; }
        });
    }

    /** Shared by policy activation and binding saves; never borrow an outer transaction or lock. */
    public static function withWorkerLock(callable $operation): mixed
    {
        global $DB;
        if (!RestPeer::idle()) { throw new \RuntimeException('Agent settings require an idle database connection.'); }
        $lock = $DB->quote('assetsync20agent:' . substr(hash('sha256', (string) $DB->dbdefault), 0, 32));
        if ((int) (Outbox::one('SELECT COALESCE(IS_USED_LOCK(' . $lock . ') = CONNECTION_ID(), 0) AS owned')['owned'] ?? 1) !== 0) {
            throw new \RuntimeException('Agent worker lock is already owned by this connection.');
        }
        if ((int) (Outbox::one('SELECT GET_LOCK(' . $lock . ', 0) AS acquired')['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('Agent is busy; settings were not changed.');
        }
        try { return $operation(); }
        finally {
            if ((int) (Outbox::one('SELECT RELEASE_LOCK(' . $lock . ') AS released')['released'] ?? 0) !== 1) {
                throw new \RuntimeException('Agent settings lock release unavailable.');
            }
        }
    }

    private static function saveLocked(array $input): void
    {
        $old = self::load();
        $transport = (string) ($input['transport'] ?? $old['transport']);
        if (!in_array($transport, ['adapter', 'rest_records'], true)) { throw new \InvalidArgumentException('Invalid agent transport.'); }
        if ($transport === 'adapter') { $settings = AgentSession::configuration($input, $old); }
        else {
            // Incoming REST admission is independent of the retained outgoing adapter credentials.
            $settings = array_intersect_key($old, array_flip(['agent_auth', 'agent_api_url', 'agent_app_token', 'agent_user_token']));
            foreach (['agent_peer_user', 'agent_peer_api_client', 'agent_peer_profile', 'agent_peer_entity'] as $key) {
                $settings[$key] = trim((string) ($input[$key] ?? $old[$key] ?? ''));
            }
            AgentApi::validatePeer($settings);
        }
        $settings['transport'] = $transport;
        $settings['mode'] = (string) ($input['mode'] ?? $old['mode'] ?? 'rest');
        if ($settings['mode'] !== 'agent') { throw new \InvalidArgumentException('Notification-only mode is retired. Configure native agent mode.'); }
        foreach (['registration', 'generation'] as $key) {
            $settings[$key] = trim((string) ($input[$key] ?? $old[$key] ?? ''));
            if (!preg_match('/^[a-f0-9]{32}$/D', $settings[$key])) { throw new \InvalidArgumentException('Valid registration and generation are required.'); }
        }
        if ($transport === 'adapter' && !self::transportReady($settings)) { throw new \InvalidArgumentException('Native Agent API URL, app token and user token are required.'); }
        // The generation is part of each outbox claim; old requests cannot acknowledge a reset.
        if ($settings['mode'] === 'agent') {
            foreach (['connection_id', 'service_user', 'service_profile'] as $key) {
                $settings[$key] = trim((string) ($input[$key] ?? $old[$key] ?? ''));
            }
            foreach (['service_user', 'service_profile'] as $key) {
                if (!ctype_digit($settings[$key]) || (int) $settings[$key] <= 0) { throw new \RuntimeException('Invalid service scope.'); }
            }
            if ($settings['connection_id'] === '' || strlen($settings['connection_id']) > 255) {
                throw new \RuntimeException('A connection binding is required.');
            }
            ServiceContext::account((int) $settings['service_user']);
            if (self::entities((int) $settings['service_user'], (int) $settings['service_profile']) === []) {
                throw new \RuntimeException('Select a profile with a direct nonrecursive entity grant.');
            }
            $user = new \User();
            $user->getFromDB((int) $settings['service_user']);
            $settings['service_name'] = (string) $user->fields['name'];
            $password = (string) ($input['service_password'] ?? '');
            if ($password === '' && $settings['service_name'] === ($old['service_name'] ?? '')) { $password = $old['service_password']; }
            if ($password === '') { throw new \RuntimeException('A dedicated service login is required.'); }
            $settings['service_password'] = (new \GLPIKey())->encrypt($password);
        }
        foreach (['agent_app_token', 'agent_user_token'] as $key) {
            $settings[$key] = (new \GLPIKey())->encrypt($settings[$key] ?? '');
        }
        \Config::setConfigurationValues(self::CONTEXT, $settings);
        $saved = \Config::getConfigurationValues(self::CONTEXT, array_keys($settings));
        foreach ($settings as $key => $value) {
            if (($saved[$key] ?? null) !== $value) { throw new \RuntimeException('Agent setting storage failed.'); }
        }
    }

    public static function install(): void
    {
        $settings = \Config::getConfigurationValues(self::CONTEXT);
        if (($settings['native_only_upgrade'] ?? '') !== '1') {
            \Config::deleteConfigurationValues(self::CONTEXT, ['token', 'endpoint', 'wake_token_hash']);
            \Config::setConfigurationValues(self::CONTEXT, ['agent_auth' => 'glpi_session',
                'wake_enabled' => '0', 'wake_binding' => '', 'native_only_upgrade' => '1']);
        }
        if (empty($settings['installation'])) {
            \Config::setConfigurationValues(self::CONTEXT, ['installation' => bin2hex(random_bytes(16))]);
        }
    }

    public static function configureWake(bool $enabled): void
    {
        $old = self::load();
        if ($enabled && !self::transportReady($old)) { throw new \RuntimeException('Wake requires a native agent binding.'); }
        if ($enabled) { ServiceContext::httpAccount((int) ($old['service_user'] ?? 0)); }
        $values = ['wake_enabled' => $enabled ? '1' : '0', 'wake_binding' => $enabled ? self::wakeBinding($old) : ''];
        \Config::setConfigurationValues(self::CONTEXT, $values);
        $saved = self::load();
        foreach ($values as $key => $value) {
            if (($saved[$key] ?? null) !== $value) { throw new \RuntimeException('Wake setting storage unavailable.'); }
        }
    }

    public static function wakeBinding(array $settings): string
    {
        $binding = [];
        $keys = ['mode', 'installation', 'registration', 'generation', 'connection_id',
            'service_user', 'service_profile', 'service_name', 'service_password'];
        foreach ($keys as $key) {
            if (!isset($settings[$key]) || (string) $settings[$key] === '') { throw new \RuntimeException('Wake binding is incomplete.'); }
            $binding[$key] = (string) $settings[$key];
        }
        $binding['transport'] = AgentSession::fingerprint($settings);
        return hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR));
    }

    public static function entities(int $user, int $profile): array
    {
        global $DB;
        $result = [];
        $rows = Outbox::sql('SELECT DISTINCT `entities_id` FROM `glpi_profiles_users` WHERE `users_id` = ' . $user
            . ' AND `profiles_id` = ' . $profile . ' AND `is_recursive` = 0 ORDER BY `entities_id` LIMIT 51');
        while ($grant = $DB->fetchAssoc($rows)) {
            $id = (int) $grant['entities_id'];
            $result[$id] = \Dropdown::getDropdownName('glpi_entities', $id);
        }
        if (count($result) > 50) { throw new \RuntimeException('Service scope exceeds 50 exact entities. Use a narrower profile.'); }
        return $result;
    }
}
