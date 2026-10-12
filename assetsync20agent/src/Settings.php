<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

final class Settings
{
    public const CONTEXT = 'plugin:assetsync20agent';

    public static function load(): array
    {
        $settings = \Config::getConfigurationValues(self::CONTEXT);
        $settings['token'] = (new \GLPIKey())->decrypt($settings['token'] ?? '') ?? '';
        $settings['service_password'] = (new \GLPIKey())->decrypt($settings['service_password'] ?? '') ?? '';
        return $settings;
    }

    public static function validEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && str_ends_with($parts['path'] ?? '', '/front/agent-notify.php') && !preg_match('/[\x00-\x20\x7f]/', $url);
    }

    public static function save(array $input): void
    {
        $old = self::load();
        $settings = [];
        $settings['mode'] = (string) ($input['mode'] ?? $old['mode'] ?? 'rest');
        if (!in_array($settings['mode'], ['rest', 'agent'], true)) {
            throw new \RuntimeException('Invalid agent mode.');
        }
        foreach (['endpoint', 'registration', 'generation', 'token'] as $key) {
            $settings[$key] = trim((string) ($input[$key] ?? ''));
        }
        if ($settings['token'] === '' && $settings['registration'] === ($old['registration'] ?? '')
            && $settings['generation'] === ($old['generation'] ?? '')) {
            $settings['token'] = $old['token'];
        }
        if (!self::validEndpoint($settings['endpoint']) || !preg_match('/^[a-f0-9]{32}$/D', $settings['registration'])
            || !preg_match('/^[a-f0-9]{32}$/D', $settings['generation']) || !preg_match('/^[a-f0-9]{64}$/D', $settings['token'])) {
            throw new \RuntimeException('Enter a direct HTTPS receiver URL and valid registration credentials.');
        }
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
        $settings['token'] = (new \GLPIKey())->encrypt($settings['token']);
        \Config::setConfigurationValues(self::CONTEXT, $settings);
    }

    public static function install(): void
    {
        $settings = \Config::getConfigurationValues(self::CONTEXT);
        if (empty($settings['installation'])) {
            \Config::setConfigurationValues(self::CONTEXT, ['installation' => bin2hex(random_bytes(16))]);
        }
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
