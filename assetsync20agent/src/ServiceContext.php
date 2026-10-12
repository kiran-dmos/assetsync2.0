<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** A real, isolated GLPI login. No synthesized session permissions. */
final class ServiceContext
{
    public static function run(array $settings, callable $operation): mixed
    {
        global $CFG_GLPI;
        $session = $_SESSION ?? [];
        $sessionId = session_id();
        $sessionStatus = session_status();
        $config = $CFG_GLPI;
        $timezone = date_default_timezone_get();
        $type = $settings['itemtype'] ?? 'Computer';
        TargetScope::model($type);
        \Glpi\Search\SearchOption::clearSearchOptionCache($type);
        try {
            self::account((int) $settings['service_user']);
            \Session::destroy();
            \Session::start();
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
        } finally {
            try {
                \Session::destroy();
                session_write_close();
                session_id($sessionId);
                if ($sessionStatus === PHP_SESSION_ACTIVE) {
                    \Session::start();
                }
                $_SESSION = $session;
                $CFG_GLPI = $config;
                \Session::loadLanguage();
                $_SESSION = $session;
                date_default_timezone_set($timezone);
            } finally {
                \Glpi\Search\SearchOption::clearSearchOptionCache($type);
            }
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
