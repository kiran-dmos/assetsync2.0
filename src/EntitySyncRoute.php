<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20;

final class EntitySyncRoute
{
    private const CONTEXT = 'plugin:assetsync20';
    private const ROUTES_KEY = 'entity_sync_routes';

    /**
     * @return list<array{id:string,name:string,glpi_b_connection_id:string,glpi_a_source_entity_id:string,glpi_a_source_entity_name:string,glpi_b_target_entity_id:string,glpi_b_target_entity_name:string,asset_types:list<string>,include_child_entities:bool,active:bool}>
     */
    public static function loadAll(): array
    {
        if (!class_exists('\Config') || !method_exists('\Config', 'getConfigurationValues')) {
            return [];
        }

        $savedValues = \Config::getConfigurationValues(self::CONTEXT, [self::ROUTES_KEY]);
        $savedJson = (string) ($savedValues[self::ROUTES_KEY] ?? '');
        if ($savedJson === '') {
            return [];
        }

        $savedRoutes = json_decode($savedJson, true);
        if (!is_array($savedRoutes)) {
            return [];
        }

        $routes = [];

        foreach ($savedRoutes as $savedRoute) {
            if (is_array($savedRoute)) {
                $routes[] = self::normalize($savedRoute);
            }
        }

        return $routes;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{id:string,name:string,glpi_b_connection_id:string,glpi_a_source_entity_id:string,glpi_a_source_entity_name:string,glpi_b_target_entity_id:string,glpi_b_target_entity_name:string,asset_types:list<string>,include_child_entities:bool,active:bool}
     */
    public static function fromInput(array $input): array
    {
        $route = self::normalize($input);

        if ($route['glpi_a_source_entity_name'] === '') {
            $route['glpi_a_source_entity_name'] = self::localEntityName($route['glpi_a_source_entity_id']);
        }

        return $route;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{id:string,name:string,glpi_b_connection_id:string,glpi_a_source_entity_id:string,glpi_a_source_entity_name:string,glpi_b_target_entity_id:string,glpi_b_target_entity_name:string,asset_types:list<string>,include_child_entities:bool,active:bool}
     */
    public static function normalize(array $input): array
    {
        return [
            'id'                         => self::routeId((string) ($input['id'] ?? '')),
            'name'                       => self::cleanText((string) ($input['name'] ?? '')),
            'glpi_b_connection_id'       => self::cleanId((string) ($input['glpi_b_connection_id'] ?? '')),
            'glpi_a_source_entity_id'    => self::cleanId((string) ($input['glpi_a_source_entity_id'] ?? '')),
            'glpi_a_source_entity_name'  => self::cleanText((string) ($input['glpi_a_source_entity_name'] ?? '')),
            'glpi_b_target_entity_id'    => self::cleanId((string) ($input['glpi_b_target_entity_id'] ?? '')),
            'glpi_b_target_entity_name'  => self::cleanText((string) ($input['glpi_b_target_entity_name'] ?? '')),
            'asset_types'                => self::cleanAssetTypes($input['asset_types'] ?? []),
            'include_child_entities'     => !empty($input['include_child_entities']),
            'active'                     => !empty($input['active']),
        ];
    }

    /**
     * @param array<string,mixed> $input
     */
    public static function save(array $input): void
    {
        $route = self::fromInput($input);
        $routes = self::loadAll();
        $saved = false;

        foreach ($routes as $index => $savedRoute) {
            if ($savedRoute['id'] === $route['id']) {
                $routes[$index] = $route;
                $saved = true;
                break;
            }
        }

        if (!$saved) {
            $routes[] = $route;
        }

        self::saveAll($routes);
    }

    public static function delete(string $id): void
    {
        $id = self::cleanId($id);
        $routes = [];

        foreach (self::loadAll() as $route) {
            if ($route['id'] !== $id) {
                $routes[] = $route;
            }
        }

        self::saveAll($routes);
    }

    /**
     * @return array{id:string,name:string,glpi_b_connection_id:string,glpi_a_source_entity_id:string,glpi_a_source_entity_name:string,glpi_b_target_entity_id:string,glpi_b_target_entity_name:string,asset_types:list<string>,include_child_entities:bool,active:bool}|null
     */
    public static function find(string $id): ?array
    {
        $id = self::cleanId($id);

        foreach (self::loadAll() as $route) {
            if ($route['id'] === $id) {
                return $route;
            }
        }

        return null;
    }

    public static function install(): void
    {
        if (
            !class_exists('\Config')
            || !method_exists('\Config', 'getConfigurationValues')
            || !method_exists('\Config', 'setConfigurationValues')
        ) {
            return;
        }

        $savedValues = \Config::getConfigurationValues(self::CONTEXT, [self::ROUTES_KEY]);
        if (!array_key_exists(self::ROUTES_KEY, $savedValues)) {
            \Config::setConfigurationValues(self::CONTEXT, [
                self::ROUTES_KEY => json_encode([], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public static function uninstall(): void
    {
        if (class_exists('\Config') && method_exists('\Config', 'deleteConfigurationValues')) {
            \Config::deleteConfigurationValues(self::CONTEXT, [self::ROUTES_KEY]);
        }
    }

    /**
     * @param list<string|int|array<string,mixed>> $entityPathIds Root-to-asset path. The asset entity may be included or omitted.
     * @return array{matches:list<array{glpi_b_connection_id:string,itemtype:string,match_depth:int,route:array{id:string,name:string,glpi_b_connection_id:string,glpi_a_source_entity_id:string,glpi_a_source_entity_name:string,glpi_b_target_entity_id:string,glpi_b_target_entity_name:string,asset_types:list<string>,include_child_entities:bool,active:bool}}>,conflicts:list<array{glpi_b_connection_id:string,itemtype:string,match_depth:int,route_ids:list<string>}>}
     */
    public static function matchAsset(
        string $itemtype,
        string $entityId,
        array $entityPathIds = [],
        ?string $connectionId = null
    ): array {
        $itemtype = self::validItemtype($itemtype);
        $entityId = self::cleanId($entityId);
        $connectionId = $connectionId !== null ? self::cleanId($connectionId) : null;

        if ($itemtype === '' || $entityId === '') {
            return [
                'matches'   => [],
                'conflicts' => [],
            ];
        }

        $entityPathIds = self::pathForAsset($entityId, $entityPathIds);
        $candidatesByConnection = [];

        foreach (self::loadAll() as $route) {
            if (!$route['active']) {
                continue;
            }

            if ($route['glpi_b_connection_id'] === '') {
                continue;
            }

            if ($connectionId !== null && $route['glpi_b_connection_id'] !== $connectionId) {
                continue;
            }

            if (!in_array($itemtype, $route['asset_types'], true)) {
                continue;
            }

            $matchDepth = self::matchDepth($route, $entityId, $entityPathIds);
            if ($matchDepth === null) {
                continue;
            }

            $candidatesByConnection[$route['glpi_b_connection_id']][] = [
                'route'       => $route,
                'match_depth' => $matchDepth,
            ];
        }

        $matches = [];
        $conflicts = [];

        foreach ($candidatesByConnection as $matchedConnectionId => $candidates) {
            $deepestDepth = self::deepestDepth($candidates);
            $deepestCandidates = [];

            foreach ($candidates as $candidate) {
                if ($candidate['match_depth'] === $deepestDepth) {
                    $deepestCandidates[] = $candidate;
                }
            }

            if (count($deepestCandidates) > 1) {
                $conflicts[] = [
                    'glpi_b_connection_id' => $matchedConnectionId,
                    'itemtype'             => $itemtype,
                    'match_depth'          => $deepestDepth,
                    'route_ids'            => self::routeIds($deepestCandidates),
                ];
                continue;
            }

            if ($deepestCandidates !== []) {
                $matches[] = [
                    'glpi_b_connection_id' => $matchedConnectionId,
                    'itemtype'             => $itemtype,
                    'match_depth'          => $deepestDepth,
                    'route'                => $deepestCandidates[0]['route'],
                ];
            }
        }

        return [
            'matches'   => $matches,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param list<string|int|array<string,mixed>> $entityPathIds
     * @return list<array{id:string,name:string,glpi_b_connection_id:string,glpi_a_source_entity_id:string,glpi_a_source_entity_name:string,glpi_b_target_entity_id:string,glpi_b_target_entity_name:string,asset_types:list<string>,include_child_entities:bool,active:bool}>
     */
    public static function matchingRoutes(
        string $itemtype,
        string $entityId,
        array $entityPathIds = [],
        ?string $connectionId = null
    ): array {
        $result = self::matchAsset($itemtype, $entityId, $entityPathIds, $connectionId);
        $routes = [];

        foreach ($result['matches'] as $match) {
            $routes[] = $match['route'];
        }

        return $routes;
    }

    /**
     * @return list<array{glpi_b_connection_id:string,itemtype:string,source_entity_id:string,route_ids:list<string>}>
     */
    public static function findConfigConflicts(): array
    {
        $routesByScope = [];

        foreach (self::loadAll() as $route) {
            if (!$route['active'] || $route['glpi_b_connection_id'] === '' || $route['glpi_a_source_entity_id'] === '') {
                continue;
            }

            foreach ($route['asset_types'] as $itemtype) {
                $scopeKey = implode('|', [
                    $route['glpi_b_connection_id'],
                    $itemtype,
                    $route['glpi_a_source_entity_id'],
                ]);

                $routesByScope[$scopeKey][] = [
                    'id'                   => $route['id'],
                    'glpi_b_connection_id' => $route['glpi_b_connection_id'],
                    'itemtype'             => $itemtype,
                    'source_entity_id'     => $route['glpi_a_source_entity_id'],
                ];
            }
        }

        $conflicts = [];

        foreach ($routesByScope as $routesInScope) {
            if (count($routesInScope) < 2) {
                continue;
            }

            $firstRoute = $routesInScope[0];
            $conflicts[] = [
                'glpi_b_connection_id' => $firstRoute['glpi_b_connection_id'],
                'itemtype'             => $firstRoute['itemtype'],
                'source_entity_id'     => $firstRoute['source_entity_id'],
                'route_ids'            => array_column($routesInScope, 'id'),
            ];
        }

        return $conflicts;
    }

    public static function localEntityName(string $entityId): string
    {
        $entityId = self::cleanId($entityId);
        if ($entityId === '') {
            return '';
        }

        if ($entityId === '0') {
            return 'Root entity';
        }

        if (class_exists('\Dropdown') && method_exists('\Dropdown', 'getDropdownName')) {
            try {
                $name = self::cleanText((string) \Dropdown::getDropdownName('glpi_entities', (int) $entityId));
                if ($name !== '') {
                    return $name;
                }
            } catch (\Throwable) {
                // Continue with the other safe lookups.
            }
        }

        if (class_exists('\Entity')) {
            try {
                $entity = new \Entity();
                if (method_exists($entity, 'getFromDB') && $entity->getFromDB((int) $entityId)) {
                    $fields = is_array($entity->fields ?? null) ? $entity->fields : [];
                    $name = self::cleanText((string) ($fields['completename'] ?? $fields['name'] ?? ''));
                    if ($name !== '') {
                        return $name;
                    }
                }
            } catch (\Throwable) {
                // Continue with the database fallback.
            }
        }

        return self::entityNameFromDatabase($entityId);
    }

    /**
     * @param list<array{id:string,name:string,glpi_b_connection_id:string,glpi_a_source_entity_id:string,glpi_a_source_entity_name:string,glpi_b_target_entity_id:string,glpi_b_target_entity_name:string,asset_types:list<string>,include_child_entities:bool,active:bool}> $routes
     */
    private static function saveAll(array $routes): void
    {
        $savedRoutes = [];

        foreach ($routes as $route) {
            $savedRoutes[] = self::normalize($route);
        }

        if (class_exists('\Config') && method_exists('\Config', 'setConfigurationValues')) {
            \Config::setConfigurationValues(self::CONTEXT, [
                self::ROUTES_KEY => json_encode($savedRoutes, JSON_THROW_ON_ERROR),
            ]);
        }
    }

    private static function routeId(string $id): string
    {
        $id = self::cleanId($id);

        if ($id !== '') {
            return $id;
        }

        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return str_replace('.', '', uniqid('route_', true));
        }
    }

    private static function cleanText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return trim(strip_tags($value));
    }

    private static function cleanId(string $value): string
    {
        return self::cleanText($value);
    }

    /**
     * @param mixed $assetTypes
     * @return list<string>
     */
    private static function cleanAssetTypes($assetTypes): array
    {
        if (!is_array($assetTypes)) {
            $assetTypes = [$assetTypes];
        }

        $selectedTypes = [];
        $validAssetTypes = FieldMapping::assetTypes();

        foreach ($assetTypes as $assetTypeKey => $assetTypeValue) {
            if (is_string($assetTypeKey) && array_key_exists($assetTypeKey, $validAssetTypes) && !empty($assetTypeValue)) {
                $selectedTypes[$assetTypeKey] = true;
                continue;
            }

            if (!is_scalar($assetTypeValue)) {
                continue;
            }

            $assetType = self::cleanText((string) $assetTypeValue);
            if (array_key_exists($assetType, $validAssetTypes)) {
                $selectedTypes[$assetType] = true;
            }
        }

        $cleanTypes = [];

        foreach (array_keys($validAssetTypes) as $assetType) {
            if (isset($selectedTypes[$assetType])) {
                $cleanTypes[] = $assetType;
            }
        }

        return $cleanTypes;
    }

    private static function validItemtype(string $itemtype): string
    {
        $itemtype = self::cleanText($itemtype);

        return array_key_exists($itemtype, FieldMapping::assetTypes()) ? $itemtype : '';
    }

    /**
     * @param list<string|int|array<string,mixed>> $entityPathIds
     * @return list<string>
     */
    private static function pathForAsset(string $entityId, array $entityPathIds): array
    {
        $path = self::cleanEntityPathIds($entityPathIds);

        if ($path === []) {
            $path = self::localEntityPathIds($entityId);
        }

        if ($path === []) {
            $path = [$entityId];
        }

        if (!in_array($entityId, $path, true)) {
            $path[] = $entityId;
        }

        return $path;
    }

    /**
     * @param list<string|int|array<string,mixed>> $entityPathIds
     * @return list<string>
     */
    private static function cleanEntityPathIds(array $entityPathIds): array
    {
        $path = [];
        $seenIds = [];

        foreach ($entityPathIds as $pathEntry) {
            $pathId = '';

            if (is_array($pathEntry)) {
                $pathId = self::cleanId((string) ($pathEntry['id'] ?? ''));
            } elseif (is_scalar($pathEntry)) {
                $pathId = self::cleanId((string) $pathEntry);
            }

            if ($pathId === '' || isset($seenIds[$pathId])) {
                continue;
            }

            $seenIds[$pathId] = true;
            $path[] = $pathId;
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private static function localEntityPathIds(string $entityId): array
    {
        $entityId = self::cleanId($entityId);
        if ($entityId === '') {
            return [];
        }

        if ($entityId === '0') {
            return ['0'];
        }

        $path = self::entityPathFromDatabase($entityId);
        if ($path !== []) {
            return $path;
        }

        if (function_exists('\getAncestorsOf')) {
            try {
                return self::cleanEntityPathIds(\getAncestorsOf('glpi_entities', (int) $entityId));
            } catch (\Throwable) {
                return [];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function entityPathFromDatabase(string $entityId): array
    {
        if (!isset($GLOBALS['DB']) || !is_object($GLOBALS['DB']) || !method_exists($GLOBALS['DB'], 'request')) {
            return [];
        }

        $path = [];
        $seenIds = [];
        $currentId = $entityId;

        while ($currentId !== '' && !isset($seenIds[$currentId])) {
            $seenIds[$currentId] = true;
            $path[] = $currentId;

            if ($currentId === '0') {
                break;
            }

            $parentId = '';

            try {
                $rows = $GLOBALS['DB']->request([
                    'SELECT' => ['id', 'entities_id'],
                    'FROM'   => 'glpi_entities',
                    'WHERE'  => ['id' => (int) $currentId],
                    'LIMIT'  => 1,
                ]);

                foreach ($rows as $row) {
                    $parentId = self::cleanId((string) ($row['entities_id'] ?? ''));
                    break;
                }
            } catch (\Throwable) {
                return [];
            }

            if ($parentId === '' || $parentId === $currentId) {
                break;
            }

            $currentId = $parentId;
        }

        return array_reverse($path);
    }

    private static function entityNameFromDatabase(string $entityId): string
    {
        if (!isset($GLOBALS['DB']) || !is_object($GLOBALS['DB']) || !method_exists($GLOBALS['DB'], 'request')) {
            return '';
        }

        try {
            $rows = $GLOBALS['DB']->request([
                'SELECT' => ['name', 'completename'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['id' => (int) $entityId],
                'LIMIT'  => 1,
            ]);

            foreach ($rows as $row) {
                return self::cleanText((string) ($row['completename'] ?? $row['name'] ?? ''));
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }

    /**
     * @param array{id:string,name:string,glpi_b_connection_id:string,glpi_a_source_entity_id:string,glpi_a_source_entity_name:string,glpi_b_target_entity_id:string,glpi_b_target_entity_name:string,asset_types:list<string>,include_child_entities:bool,active:bool} $route
     * @param list<string> $entityPathIds
     */
    private static function matchDepth(array $route, string $entityId, array $entityPathIds): ?int
    {
        $sourceEntityId = $route['glpi_a_source_entity_id'];
        if ($sourceEntityId === '') {
            return null;
        }

        foreach ($entityPathIds as $depth => $pathEntityId) {
            if ($pathEntityId !== $sourceEntityId) {
                continue;
            }

            if ($pathEntityId === $entityId || $route['include_child_entities']) {
                return $depth;
            }

            return null;
        }

        return null;
    }

    /**
     * @param list<array{route:array{id:string},match_depth:int}> $candidates
     */
    private static function deepestDepth(array $candidates): int
    {
        $deepestDepth = 0;

        foreach ($candidates as $candidate) {
            if ($candidate['match_depth'] > $deepestDepth) {
                $deepestDepth = $candidate['match_depth'];
            }
        }

        return $deepestDepth;
    }

    /**
     * @param list<array{route:array{id:string},match_depth:int}> $candidates
     * @return list<string>
     */
    private static function routeIds(array $candidates): array
    {
        $routeIds = [];

        foreach ($candidates as $candidate) {
            $routeIds[] = $candidate['route']['id'];
        }

        return $routeIds;
    }
}
