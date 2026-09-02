<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class Menu
{
    public static function getTypeName(int $nb = 0): string
    {
        return Plugin::NAME;
    }

    public static function getMenuName(): string
    {
        return Plugin::NAME;
    }

    public static function getMenuContent(): array
    {
        if (class_exists('Session') && !\Session::haveRight('config', defined('READ') ? READ : 1)) {
            return [];
        }

        $page = self::configUrl();

        return [
            'title' => Plugin::NAME,
            'page'  => $page,
            'icon'  => 'ti ti-refresh',
            'links' => [
                'config'           => $page,
                'fieldmapping'     => self::fieldMappingUrl(),
                'entitysyncroutes' => self::entitySyncRoutesUrl(),
            ],
        ];
    }

    public static function configUrl(): string
    {
        if (class_exists('Plugin') && method_exists('Plugin', 'getWebDir')) {
            try {
                return \Plugin::getWebDir(Plugin::KEY) . '/front/config.php';
            } catch (\Throwable) {
                // GLPI may not have fully initialized web paths in CLI smoke tests.
            }
        }

        return '/plugins/' . Plugin::KEY . '/front/config.php';
    }

    public static function fieldMappingUrl(): string
    {
        if (class_exists('Plugin') && method_exists('Plugin', 'getWebDir')) {
            try {
                return \Plugin::getWebDir(Plugin::KEY) . '/front/fieldmapping.php';
            } catch (\Throwable) {
                // GLPI may not have fully initialized web paths in CLI smoke tests.
            }
        }

        return '/plugins/' . Plugin::KEY . '/front/fieldmapping.php';
    }

    public static function entitySyncRoutesUrl(): string
    {
        if (class_exists('Plugin') && method_exists('Plugin', 'getWebDir')) {
            try {
                return \Plugin::getWebDir(Plugin::KEY) . '/front/entitysyncroutes.php';
            } catch (\Throwable) {
                // GLPI may not have fully initialized web paths in CLI smoke tests.
            }
        }

        return '/plugins/' . Plugin::KEY . '/front/entitysyncroutes.php';
    }
}
