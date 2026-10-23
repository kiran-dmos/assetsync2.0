<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** Validates the GLPI REST Session-Token produced from the configured App/User Token. */
final class ApiSessionAuthenticator
{
    public static function serviceUserIsAuthenticated(int $expectedUserId): bool
    {
        if ($expectedUserId <= 0) {
            return false;
        }
        $raw = trim((string) ($_SERVER['HTTP_SESSION_TOKEN'] ?? ''));
        if ($raw === '') {
            return false;
        }
        try {
            $encoded = base64_decode($raw, true);
            $sessionId = (new \GLPIKey())->decrypt($encoded === false ? $raw : $encoded);
        } catch (\Throwable) {
            return false;
        }
        if (!is_string($sessionId) || $sessionId === '' || preg_match('/[\x00-\x20]/', $sessionId)) {
            return false;
        }
        try {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            session_id($sessionId);
            if (class_exists('\Session') && method_exists('\Session', 'start')) {
                \Session::start();
            } else {
                session_start();
            }
            return (int) (\Session::getLoginUserID() ?? 0) === $expectedUserId;
        } catch (\Throwable) {
            return false;
        }
    }
}
