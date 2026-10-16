<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** A real, isolated GLPI login. No synthesized session permissions. */
final class ServiceContext
{
    public static function run(array $settings, callable $operation): mixed
    {
        global $CFG_GLPI, $TRANSLATE;
        $session = $_SESSION ?? [];
        $sessionId = session_id();
        $sessionStatus = session_status();
        $config = $CFG_GLPI;
        $translation = $TRANSLATE ?? null;
        $request = $_REQUEST;
        $timezone = date_default_timezone_get();
        $type = $settings['itemtype'] ?? 'Computer';
        TargetScope::model($type);
        \Glpi\Search\SearchOption::clearSearchOptionCache($type);
        $http = PHP_SAPI !== 'cli';
        $temporarySessionId = '';
        $sessionOptions = [];
        if ($http) {
            if ($sessionStatus === PHP_SESSION_ACTIVE) { throw new \RuntimeException('HTTP service execution requires a stateless request.'); }
            self::httpAccount((int) $settings['service_user']);
        }
        try {
            if ($http) {
                foreach (['session.use_cookies', 'session.use_only_cookies', 'session.use_trans_sid'] as $option) {
                    $previous = ini_get($option);
                    if ($previous === false) { throw new \RuntimeException('HTTP service session policy unavailable.'); }
                    $sessionOptions[$option] = $previous;
                    if ((ini_set($option, '0') === false && $previous !== '0')
                        || ini_get($option) !== '0') {
                        throw new \RuntimeException('HTTP service session isolation unavailable.');
                    }
                }
                session_id('');
            }
            self::account((int) $settings['service_user']);
            \Session::destroy();
            \Session::start();
            if ($http) { $temporarySessionId = session_id(); }
            $auth = new \Auth();
            if (!$auth->login($settings['service_name'], $settings['service_password'], true)
                || (int) \Session::getLoginUserID() !== (int) $settings['service_user']) {
                throw new \RuntimeException('Service authentication failed.');
            }
            $profile = (int) $settings['service_profile'];
            if (!isset($_SESSION['glpiprofiles'][$profile])) {
                throw new \RuntimeException('Service profile is not assigned.');
            }
            self::grant($settings);
            \Session::changeProfile($profile);
            if (!\Session::changeActiveEntities((int) $settings['entity_id'], false)) {
                throw new \RuntimeException('Service entity activation failed.');
            }
            return $operation();
        } catch (\Glpi\Exception\RedirectException $error) {
            throw new \RuntimeException('Service authentication requires an interactive step.', 0, $error);
        } finally {
            try {
                if ($http) {
                    $currentId = session_id();
                    if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
                    if ($temporarySessionId !== '' && $temporarySessionId !== $currentId) {
                        session_id($temporarySessionId);
                        session_start();
                        session_destroy();
                    }
                } else { \Session::destroy(); }
                session_write_close();
                session_id($sessionId);
                foreach ($sessionOptions as $option => $value) { ini_set($option, $value); }
                if ($sessionStatus === PHP_SESSION_ACTIVE) {
                    \Session::start();
                }
                $_SESSION = $session;
                $CFG_GLPI = $config;
                \Session::loadLanguage();
                $_SESSION = $session;
                date_default_timezone_set($timezone);
            } finally {
                $_SESSION = $session;
                $CFG_GLPI = $config;
                $TRANSLATE = $translation;
                $_REQUEST = $request;
                date_default_timezone_set($timezone);
                \Glpi\Search\SearchOption::clearSearchOptionCache($type);
            }
        }
    }

    /** Official MFA helpers can return false for an unreadable secret; persisted data also fences login. */
    public static function httpAccount(int $id): void
    {
        global $CFG_GLPI;
        self::account($id);
        $user = new \User();
        if (!$user->getFromDB($id) || !array_key_exists('2fa', $user->fields)
            || !array_key_exists('2fa_unenforced', $user->fields)
            || !in_array($user->fields['2fa_unenforced'], [0, 1, '0', '1'], true)
            || !in_array($CFG_GLPI['2fa_enforced'] ?? null, [0, 1, '0', '1'], true)
            || !class_exists(\Glpi\Security\TOTPManager::class)) { throw new \RuntimeException('HTTP MFA policy unavailable.'); }
        $mfa = new \Glpi\Security\TOTPManager();
        if ($mfa->is2FAEnabled($id) || $mfa->get2FAEnforcement($id) !== \Glpi\Security\TOTPManager::ENFORCEMENT_OPTIONAL
            || ($user->fields['2fa'] !== null && $user->fields['2fa'] !== '')) {
            throw new \RuntimeException('HTTP service login requires optional, unconfigured MFA.');
        }
    }

    public static function account(int $id): void
    {
        $user = new \User();
        $now = date('Y-m-d H:i:s');
        if (!$user->getFromDB($id) || !$user->fields['is_active'] || $user->fields['is_deleted']
            || (!empty($user->fields['begin_date']) && $user->fields['begin_date'] >= $now)
            || (!empty($user->fields['end_date']) && $user->fields['end_date'] <= $now)) {
            throw new \RuntimeException('Service account is unavailable.');
        }
    }

    public static function grant(array $settings): void
    {
        $grant = new \Profile_User();
        if (!$grant->getFromDBByCrit(['users_id' => (int) $settings['service_user'],
            'profiles_id' => (int) $settings['service_profile'], 'entities_id' => (int) $settings['entity_id'], 'is_recursive' => 0])) {
            throw new \RuntimeException('An exact nonrecursive service grant is required.');
        }
    }

    public static function computer(array $settings): object
    {
        self::account((int) $settings['service_user']);
        self::grant($settings);
        $profile = new \Profile();
        $type = $settings['itemtype'] ?? 'Computer';
        $item = TargetScope::model($type);
        if ((int) \Session::getLoginUserID() !== (int) $settings['service_user']
            || \Session::isCron() || \Session::isInventory() || \Session::isRightChecksDisabled()
            || (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) !== (int) $settings['service_profile']
            || (int) ($_SESSION['glpiactive_entity'] ?? -1) !== (int) $settings['entity_id']
            || !$profile->getFromDB((int) $settings['service_profile'])
            || ((int) ($profile->fields[strtolower($type)] ?? 0) & (READ | UPDATE)) !== (READ | UPDATE)) {
            throw new \RuntimeException('Service asset authorization denied.');
        }
        $id = (int) ($settings['items_id'] ?? $settings['computer_id']);
        if (!$item->getFromDB($id) || $item->fields['is_deleted'] || $item->fields['is_template']
            || (int) $item->fields['entities_id'] !== (int) $settings['entity_id']
            || !\Session::haveAccessToEntity((int) $settings['entity_id'])
            || !$item->can($id, READ) || !$item->can($id, UPDATE)) {
            throw new \RuntimeException('Computer is outside the authorized pair.');
        }
        return $item;
    }
}
