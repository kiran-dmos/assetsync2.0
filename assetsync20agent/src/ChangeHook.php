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
            $parentType = $type;
            if (isset(TargetScope::types()[$type]) && TargetScope::types()[$type] === '') {
                $id = (int) ($fields['id'] ?? 0);
            } elseif (in_array($type, Snapshot::hookTypes(), true) && in_array($fields['itemtype'] ?? '', ['Computer', 'Printer'], true)) {
                $parentType = $fields['itemtype'];
                $id = (int) ($fields['items_id'] ?? 0);
                $matched = false;
                foreach (Snapshot::containers($parentType) as $container) {
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
                $settings = Settings::load();
                if (($settings['mode'] ?? 'rest') === 'agent') {
                    TargetScope::dirty($parentType, $id, $settings);
                    return;
                }
                if (($settings['mode'] ?? 'rest') !== 'agent' && $parentType !== 'Computer') { return; }
                $snapshot = $purged ? ['fingerprint' => hash('sha256', 'purged'), 'lifecycle' => 'purged'] : Snapshot::read($id, null, $parentType);
                Outbox::observe($id, $snapshot, false, null, $parentType);
            }
        } catch (\Throwable) {
            // Reconciliation repairs missed hooks. Never leak field values/credentials into logs.
            \Toolbox::logInFile('assetsync20agent', "Local asset notification failed; reconciliation required.\n");
        }
    }
}
