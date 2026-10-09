<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

final class ChangeHook
{
    public static function changed(object $item): void
    {
        self::record($item, false);
    }

    public static function purge(object $item): void
    {
        self::record($item, true);
    }

    private static function record(object $item, bool $purged): void
    {
        try {
            $type = method_exists($item, 'getType') ? $item::getType() : $item::class;
            $fields = $item->fields ?? [];
            if ($type === 'Computer') {
                $id = (int) ($fields['id'] ?? 0);
            } elseif (in_array($type, Snapshot::hookTypes(), true) && ($fields['itemtype'] ?? '') === 'Computer') {
                $id = (int) ($fields['items_id'] ?? 0);
                $matched = false;
                foreach (Snapshot::containers() as $container) {
                    if ($container['class'] === $type
                        && (int) ($fields['plugin_fields_containers_id'] ?? 0) === (int) $container['id']) {
                        $matched = true;
                    }
                }
                if (!$matched) {
                    return;
                }
                $purged = false;
            } else {
                return;
            }
            if ($id > 0) {
                $snapshot = $purged ? ['fingerprint' => hash('sha256', 'purged'), 'lifecycle' => 'purged'] : Snapshot::read($id);
                Outbox::observe($id, $snapshot, false);
            }
        } catch (\Throwable) {
            // Reconciliation repairs missed hooks. Never leak field values/credentials into logs.
            \Toolbox::logInFile('assetsync20agent', "Local Computer notification failed; reconciliation required.\n");
        }
    }
}
