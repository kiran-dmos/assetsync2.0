<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** A wake authorizes one bounded worker turn, never an asset command. */
final class Wake
{
    public const SECONDS = 8;
    public const INTERVAL = 10;
    private static ?array $admittedSettings = null;

    public static function assertCurrent(): void
    {
        if (self::$admittedSettings !== null) { self::settings(); }
    }

    /** A settings change ends this HTTP turn; it never enables the CLI REST fallback. */
    public static function settings(): array
    {
        $current = Settings::load();
        if (self::$admittedSettings !== null) {
            if (($current['mode'] ?? '') !== 'agent' || ($current['wake_enabled'] ?? '') !== '1'
                || ($current['wake_token_hash'] ?? '') !== self::$admittedSettings['wake_token_hash']
                || ($current['wake_binding'] ?? '') !== self::$admittedSettings['wake_binding']
                || Settings::wakeBinding($current) !== Settings::wakeBinding(self::$admittedSettings)) {
                throw new \UnexpectedValueException('Admitted wake binding changed.');
            }
        }
        return $current;
    }

    public static function authorize(array $request, string $token, array $settings, ?AgentApi $api = null): void
    {
        $keys = array_keys($request); sort($keys);
        if ($keys !== ['connection_id', 'generation', 'installation', 'kind', 'registration', 'version']
            || $request['version'] !== 1 || $request['kind'] !== 'wake') { throw new \InvalidArgumentException('Invalid wake schema.'); }
        foreach (['installation', 'registration', 'generation'] as $key) {
            if (!is_string($request[$key]) || !preg_match('/^[a-f0-9]{32}$/D', $request[$key])) { throw new \InvalidArgumentException('Invalid wake binding.'); }
        }
        if (!is_string($request['connection_id']) || $request['connection_id'] === '' || strlen($request['connection_id']) > 255
            || preg_match('/[\x00-\x20\x7f]/', $request['connection_id'])) { throw new \InvalidArgumentException('Invalid connection binding.'); }
        if (($settings['wake_enabled'] ?? '0') !== '1' || ($settings['mode'] ?? '') !== 'agent'
            || ($api === null && (($settings['agent_auth'] ?? 'legacy_bearer') !== 'legacy_bearer'
                || !preg_match('/^[a-f0-9]{64}$/D', $token) || !preg_match('/^[a-f0-9]{64}$/D', $settings['wake_token_hash'] ?? '')
                || !hash_equals($settings['wake_token_hash'], hash('sha256', $token))))
            || !hash_equals($settings['wake_binding'] ?? '', Settings::wakeBinding($settings))) {
            throw new \UnexpectedValueException('Wake authorization unavailable.');
        }
        if ($api !== null) { $api->assertPeer($settings); }
        foreach (['installation', 'registration', 'generation', 'connection_id'] as $key) {
            if ($request[$key] !== (string) ($settings[$key] ?? '')) { throw new \UnexpectedValueException('Wake binding changed.'); }
        }
    }

    public static function receive(array $request, string $token, ?AgentApi $api = null): array
    {
        if (!defined(Worker::class . '::WAKE_VERSION') || Worker::WAKE_VERSION !== 1) {
            throw new \RuntimeException('HTTPS wake requires the upgraded worker.');
        }
        $deadline = hrtime(true) + self::SECONDS * 1_000_000_000;
        self::authorize($request, $token, Settings::load(), $api);
        $admitted = false;
        try {
            $count = (new Worker())->run($deadline, static function () use ($request, $token, $api, &$admitted): void {
            $current = Settings::load();
            self::authorize($request, $token, $current, $api);
            ServiceContext::httpAccount((int) $current['service_user']);
            $last = $current['wake_last_attempt'] ?? '0';
            if (!is_string($last) || !ctype_digit($last) || (int) $last > time()
                || ((int) $last > 0 && time() - (int) $last < self::INTERVAL)) { throw new \RuntimeException('Wake rate limited.', 429); }
            $now = (string) time();
            \Config::setConfigurationValues(Settings::CONTEXT, ['wake_last_attempt' => $now]);
            if ((Settings::load()['wake_last_attempt'] ?? null) !== $now) { throw new \RuntimeException('Wake rate storage unavailable.'); }
            $current = Settings::load();
            self::authorize($request, $token, $current, $api);
            self::$admittedSettings = $current;
            $admitted = true;
            });
            return ['status' => 200, 'version' => 1, 'state' => $admitted ? 'completed' : 'busy', 'attempted_notifications' => $count]
                + array_intersect_key($request, array_flip(['installation', 'registration', 'generation', 'connection_id']));
        } finally { self::$admittedSettings = null; }
    }
}
