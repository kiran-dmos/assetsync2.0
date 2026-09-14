<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class AssetChangeHook
{
    private static int $suppressionDepth = 0;

    public static function onAdd(object $item): void
    {
        self::queueItem($item);
    }

    public static function onUpdate(object $item): void
    {
        self::queueItem($item);
    }

    public static function withoutQueue(callable $callback): bool
    {
        self::$suppressionDepth++;

        try {
            return (bool) $callback();
        } finally {
            self::$suppressionDepth--;
        }
    }

    /**
     * @return list<string>
     */
    public static function customHookItemtypesFor(string $itemtype): array
    {
        $hookItemtypes = [];

        try {
            foreach (FieldsText::localOptions($itemtype) as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $key = FieldsText::key($itemtype, $option);
                if ($key === '') {
                    continue;
                }

                $descriptor = FieldsText::descriptor($itemtype, $key);
                $hookItemtypes[$descriptor['class']] = true;
            }
        } catch (\Throwable) {
            // Fall back to the container table below.
        }

        foreach (self::customHookItemtypesFromContainers($itemtype) as $customItemtype) {
            $hookItemtypes[$customItemtype] = true;
        }

        return array_keys($hookItemtypes);
    }

    /**
     * @return list<string>
     */
    private static function customHookItemtypesFromContainers(string $itemtype): array
    {
        global $DB;

        if (
            !isset($DB)
            || !is_object($DB)
            || !method_exists($DB, 'request')
            || !method_exists($DB, 'tableExists')
            || !$DB->tableExists('glpi_plugin_fields_containers')
            || !function_exists('\\getItemTypeForTable')
        ) {
            return [];
        }

        $hookItemtypes = [];
        $rows = $DB->request([
            'FROM' => 'glpi_plugin_fields_containers',
            'WHERE' => ['is_active' => 1],
        ]);

        foreach ($rows as $row) {
            if (!is_array($row) || !self::containerAppliesTo($row, $itemtype)) {
                continue;
            }

            foreach (self::containerTables($itemtype, $row) as $table) {
                if ($DB->tableExists($table)) {
                    $hookItemtypes[\getItemTypeForTable($table)] = true;
                    break;
                }
            }
        }

        return array_keys($hookItemtypes);
    }

    /**
     * @param array<string,mixed> $container
     */
    private static function containerAppliesTo(array $container, string $itemtype): bool
    {
        $itemtypes = json_decode((string) ($container['itemtypes'] ?? ''), true);

        return is_array($itemtypes) && in_array($itemtype, $itemtypes, true);
    }

    /**
     * @param array<string,mixed> $container
     * @return list<string>
     */
    private static function containerTables(string $itemtype, array $container): array
    {
        $name = is_scalar($container['name'] ?? null)
            ? preg_replace('/[^a-z0-9]/', '', strtolower((string) $container['name']))
            : '';
        if (!is_string($name) || $name === '') {
            return [];
        }

        $base = 'glpi_plugin_fields_' . strtolower($itemtype) . $name;

        return [$base, $base . 's'];
    }

    private static function queueItem(object $item): void
    {
        if (self::$suppressionDepth > 0) {
            return;
        }

        try {
            $assetRef = self::assetRef($item);
            if ($assetRef === null) {
                return;
            }

            [$itemtype, $itemsId] = $assetRef;
            $service = new AssetSyncService();
            foreach (GlpiBConnection::loadAll() as $connection) {
                if (!empty($connection['active'])) {
                    $service->queueAssetIfNeeded($itemtype, $itemsId, (string) $connection['id']);
                }
            }
        } catch (\Throwable $error) {
            self::logFailure($error);
        }
    }

    /**
     * @return array{0:string,1:int}|null
     */
    private static function assetRef(object $item): ?array
    {
        $itemtype = self::itemtype($item);
        if (array_key_exists($itemtype, FieldMapping::assetTypes())) {
            $itemsId = self::itemsId($item);

            return $itemsId > 0 ? [$itemtype, $itemsId] : null;
        }

        if (!FieldsText::isCustom($itemtype)) {
            return null;
        }

        $fields = is_array($item->fields ?? null) ? $item->fields : [];
        $input = is_array($item->input ?? null) ? $item->input : [];
        $parentItemtype = (string) ($fields['itemtype'] ?? $input['itemtype'] ?? '');
        $parentItemsId = (int) ($fields['items_id'] ?? $input['items_id'] ?? 0);

        if (!array_key_exists($parentItemtype, FieldMapping::assetTypes()) || $parentItemsId <= 0) {
            return null;
        }

        return [$parentItemtype, $parentItemsId];
    }

    private static function itemtype(object $item): string
    {
        if (method_exists($item, 'getType')) {
            return (string) $item::getType();
        }

        return $item::class;
    }

    private static function itemsId(object $item): int
    {
        $fields = is_array($item->fields ?? null) ? $item->fields : [];
        $input = is_array($item->input ?? null) ? $item->input : [];
        $id = (int) ($fields['id'] ?? $input['id'] ?? 0);

        if ($id <= 0 && method_exists($item, 'getID')) {
            $id = (int) $item->getID();
        }

        return $id;
    }

    private static function logFailure(\Throwable $error): void
    {
        if (class_exists('\Toolbox') && method_exists('\Toolbox', 'logInFile')) {
            \Toolbox::logInFile('assetsync20', 'AssetSync2.0 asset change hook failed: ' . $error->getMessage() . PHP_EOL);
        }
    }
}
