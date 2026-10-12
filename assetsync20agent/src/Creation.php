<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

/** Protocol v5 initialization is restricted to a new parent and its local Fields rows. */
final class Creation
{
    public static function validate(array $task): void
    {
        if ($task['pair_id'] !== '' || !is_int($task['items_id']) || $task['serial'] !== ''
            || $task['identity'] === ['serial' => '', 'otherserial' => '']
            || ($task['kind'] === 'confirm_create' ? $task['items_id'] <= 0 : $task['items_id'] !== 0)
            || !is_array($task['seed']) || count($task['seed']) > 32
            || ($task['kind'] === 'confirm_create' ? $task['seed'] !== [] : $task['seed'] === [])
            || !is_string($task['prerequisites'])
            || ($task['kind'] === 'create' ? !preg_match('/^[a-f0-9]{64}$/D', $task['prerequisites']) : $task['prerequisites'] !== '')) {
            throw new \RuntimeException('Invalid creation task.');
        }
        foreach ($task['seed'] as $key => $seed) {
            if (!is_string($key) || !is_array($seed) || array_keys($seed) !== ['type', 'value']
                || !is_string($seed['type']) || TaskExecutor::normalizeValue($seed['type'], $seed['value'], true) !== $seed['value']
                || (is_string($seed['value']) && strlen($seed['value']) > 16384)
                || (in_array($key, ['name', 'comment'], true) ? $seed['type'] !== 'text'
                    : !preg_match('/^' . preg_quote($task['itemtype'], '/') . '\.PluginFields[A-Za-z0-9]+\.[a-z][a-z0-9_]*$/D', $key))) {
                throw new \RuntimeException('Invalid creation seed.');
            }
        }
    }

    private static function noMatch(array $evidence): bool
    {
        return $evidence['complete'] === true && $evidence['lookup_complete'] === ['serial' => true, 'otherserial' => true]
            && $evidence['serial'] === [] && $evidence['otherserial'] === [];
    }

    /** Fresh metadata does not require an existing parent or child ID. */
    public static function prerequisites(array $task, array $settings): array
    {
        global $DB;
        ServiceContext::account((int) $settings['service_user']);
        ServiceContext::grant($settings);
        $profile = new \Profile();
        $rights = CREATE | READ | UPDATE;
        if ((int) \Session::getLoginUserID() !== (int) $settings['service_user']
            || \Session::isCron() || \Session::isInventory() || \Session::isRightChecksDisabled()
            || (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) !== (int) $settings['service_profile']
            || (int) ($_SESSION['glpiactive_entity'] ?? -1) !== $task['entity_id']
            || !\Session::haveAccessToEntity($task['entity_id']) || !$profile->getFromDB((int) $settings['service_profile'])
            || ((int) ($profile->fields[strtolower($task['itemtype'])] ?? 0) & $rights) !== $rights) {
            throw new \RuntimeException('Creation requires fresh CREATE, READ, UPDATE and exact entity authorization.');
        }
        $item = TargetScope::model($task['itemtype']);
        $input = ['entities_id' => $task['entity_id'], 'is_deleted' => 0, 'is_template' => 0] + $task['identity'];
        if (!$item->can(-1, CREATE, $input)) { throw new \RuntimeException('Model creation permission denied.'); }
        \Glpi\Search\SearchOption::clearSearchOptionCache($task['itemtype']);
        $options = \Glpi\Search\SearchOption::getOptionsForItemtype($task['itemtype']);
        $fields = [];
        foreach (['name', 'comment'] as $column) {
            $matches = array_filter($options, static fn ($option): bool => is_array($option)
                && ($option['table'] ?? '') === $item::getTable() && ($option['field'] ?? '') === $column);
            if (count($matches) !== 1) { throw new \RuntimeException('Creation native metadata is ambiguous.'); }
            $fields[$column] = ['type' => 'text', 'definition' => $matches];
        }
        $containers = [];
        $tables = [$item::getTable(), TaskJournal::TABLE, 'glpi_logs'];
        if (\Plugin::isPluginActive('fields')) {
            if (!$DB->tableExists('glpi_plugin_fields_containers') || !$DB->tableExists('glpi_plugin_fields_fields')) {
                throw new \RuntimeException('Fields metadata unavailable.');
            }
            $available = array_column(Snapshot::containers($task['itemtype']), null, 'id');
            $count = 0;
            foreach ($DB->request(['FROM' => 'glpi_plugin_fields_containers', 'WHERE' => ['is_active' => 1], 'ORDER' => 'id ASC', 'LIMIT' => 101]) as $row) {
                if (++$count > 100) { throw new \RuntimeException('Creation container budget exceeded.'); }
                $types = json_decode((string) $row['itemtypes'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($types)) { throw new \RuntimeException('Invalid Fields itemtypes.'); }
                if (!in_array($task['itemtype'], $types, true)) { continue; }
                $applicable = (int) $row['entities_id'] === $task['entity_id'];
                if (!$applicable && $row['is_recursive']) {
                    $applicable = in_array($task['entity_id'], array_map('intval', \getSonsOf('glpi_entities', (int) $row['entities_id'])), true);
                }
                if (!$applicable) { continue; }
                $id = (int) $row['id'];
                if ($row['is_recursive'] || !isset($available[$id])
                    || \PluginFieldsProfile::getRightOnContainer((int) $settings['service_profile'], $id) <= READ) {
                    throw new \RuntimeException('Creation Fields scope, model or permission unavailable.');
                }
                $container = $available[$id];
                $tables[] = $container['table'];
                $definitions = iterator_to_array($DB->request(['FROM' => 'glpi_plugin_fields_fields',
                    'WHERE' => ['plugin_fields_containers_id' => $id, 'is_active' => 1], 'ORDER' => 'id ASC', 'LIMIT' => 101]));
                if (count($definitions) > 100) { throw new \RuntimeException('Creation field budget exceeded.'); }
                $overrides = \PluginFieldsStatusOverride::getOverridesForItemtypeAndStatus($id, $task['itemtype'], 0);
                $values = [];
                foreach ($definitions as $definition) {
                    if (!array_key_exists('mandatory', $definition) || !array_key_exists('is_readonly', $definition)) {
                        throw new \RuntimeException('Creation mandatory metadata unavailable.');
                    }
                    $mandatory = !empty($definition['mandatory']);
                    foreach ($overrides as $override) {
                        if ((int) $override['plugin_fields_fields_id'] === (int) $definition['id']) {
                            $mandatory = $mandatory || !empty($override['mandatory']);
                        }
                    }
                    $type = $definition['type'];
                    if (!in_array($type, ['text', 'yesno', 'date'], true)) {
                        if ($mandatory && $type !== 'header') { throw new \RuntimeException('Unsupported mandatory creation field.'); }
                        continue;
                    }
                    $column = $definition['name'];
                    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $column) || !$DB->fieldExists($container['table'], $column)) {
                        throw new \RuntimeException('Creation Fields column unavailable.');
                    }
                    $matches = array_filter($options, static fn ($option): bool => is_array($option)
                        && ($option['table'] ?? '') === $container['table'] && ($option['field'] ?? '') === $column);
                    if (count($matches) !== 1 || ($type === 'yesno' && reset($matches)['datatype'] !== 'bool')
                        || ($type === 'date' && reset($matches)['datatype'] !== 'date')) {
                        throw new \RuntimeException('Creation Fields metadata missing or ambiguous.');
                    }
                    $key = $task['itemtype'] . '.' . $container['class'] . '.' . $column;
                    $default = $definition['default_value'] ?? ($type === 'yesno' ? 0 : '');
                    if ($type === 'yesno' && ($default === '' || $default === null)) { $default = 0; }
                    $value = $task['seed'][$key]['value'] ?? $default;
                    $value = TaskExecutor::normalizeValue($type, $value, true);
                    if (($mandatory && $type !== 'yesno' && empty($value)) || (is_string($value) && mb_strlen($value) > 255)) {
                        throw new \RuntimeException('Mandatory or oversized creation value.');
                    }
                    $fields[$key] = ['type' => $type, 'definition' => $definition, 'options' => $matches,
                        'container' => $id, 'column' => $column, 'value' => $value];
                    $values[$column] = $value;
                }
                if (count($fields) > 32) { throw new \RuntimeException('Creation scalar budget exceeded.'); }
                $containers[$id] = ['metadata' => $container, 'definitions' => $definitions, 'overrides' => $overrides, 'values' => $values];
                // Validate all mandatory fields together. Never use massiveaction to skip absent values.
                if (!\PluginFieldsContainer::validateValues(['plugin_fields_containers_id' => $id, 'states_id' => 0] + $values, $task['itemtype'], false)) {
                    throw new \RuntimeException('Fields rejected creation initialization.');
                }
            }
        }
        foreach ($task['seed'] as $key => $seed) {
            if (!isset($fields[$key]) || $fields[$key]['type'] !== $seed['type']) { throw new \RuntimeException('Mapped creation field unavailable.'); }
            if (in_array($key, ['name', 'comment'], true)) {
                if ($key === 'name' && mb_strlen($seed['value']) > 255) { throw new \RuntimeException('Creation name too long.'); }
                $input[$key] = $seed['value'];
            }
        }
        foreach (array_unique($tables) as $table) {
            $engine = Outbox::one('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $DB->quote($table));
            if (strtolower((string) ($engine['ENGINE'] ?? '')) !== 'innodb') { throw new \RuntimeException('Creation requires transactional storage.'); }
        }
        return ['input' => $input, 'fields' => $fields, 'containers' => $containers];
    }

    public static function execute(array $task, array $settings): array
    {
        global $DB;
        if ($task['kind'] === 'confirm_create') {
            return ['outcome' => 'unchanged', 'changed' => [], 'evidence' => TaskExecutor::matchEvidence($task, $settings),
                'snapshot' => TaskExecutor::snapshot($settings, 4)];
        }
        $evidence = TaskExecutor::matchEvidence($task, $settings);
        if (!self::noMatch($evidence)) {
            if ($evidence['lookup_complete'] !== ['serial' => true, 'otherserial' => true]) {
                throw new \RuntimeException('Creation needs complete identity evidence.');
            }
            return ['outcome' => 'stale', 'changed' => [], 'existing' => true, 'evidence' => $evidence];
        }
        $plan = self::prerequisites($task, $settings);
        $hash = TaskExecutor::hash($plan);
        if ($task['kind'] === 'prepare_create') {
            return ['outcome' => 'unchanged', 'changed' => [], 'prerequisites' => $hash, 'evidence' => $evidence];
        }
        if ($hash !== $task['prerequisites']) { return ['outcome' => 'stale', 'changed' => []]; }
        $attempted = false;
        $id = null;
        Outbox::sql('START TRANSACTION');
        try {
            $journal = Outbox::one('SELECT * FROM `' . TaskJournal::TABLE . '` WHERE `id` = ' . $DB->quote($task['id']) . ' FOR UPDATE');
            if (!$journal || $journal['hash'] !== $task['hash'] || $journal['state'] !== 'executing' || $journal['result'] !== '{}') {
                throw new \RuntimeException('Committed creation intent required.');
            }
            Outbox::sql('SAVEPOINT assetsync_create');
            TaskExecutor::validate($task, $settings);
            TaskExecutor::configuration($settings);
            if (TaskExecutor::hash(self::prerequisites($task, $settings)) !== $hash) { throw new \RuntimeException('Creation metadata changed.'); }
            $evidence = TaskExecutor::matchEvidence($task, $settings);
            if (!self::noMatch($evidence)) {
                Outbox::sql('ROLLBACK');
                return $evidence['lookup_complete'] === ['serial' => true, 'otherserial' => true]
                    ? ['outcome' => 'stale', 'changed' => [], 'existing' => true, 'evidence' => $evidence]
                    : ['outcome' => 'blocked', 'changed' => []];
            }
            $input = $plan['input'];
            // The normal parent pre-add hook validates its dom container before inserting the parent.
            foreach ($plan['containers'] as $container) {
                if ($container['metadata']['type'] === 'dom') { $input += $container['values']; }
            }
            $item = TargetScope::model($task['itemtype']);
            $attempted = true;
            $id = $item->add($input);
            if (!is_numeric($id) || (int) $id <= 0) { throw new \RuntimeException('Model creation failed.'); }
            $id = (int) $id;
            $target = array_replace($settings, ['items_id' => $id]);
            $parent = ServiceContext::computer($target);
            if (TaskExecutor::identity($parent->fields) !== $task['identity']) { throw new \RuntimeException('Created identity changed by model rules.'); }
            foreach ($plan['containers'] as $containerId => $container) {
                TaskExecutor::configuration($settings);
                // Recheck CREATE and container rights after parent hooks before each child write.
                if (TaskExecutor::hash(self::prerequisites($task, $settings)) !== $hash) { throw new \RuntimeException('Creation permissions or metadata changed.'); }
                $statusField = \PluginFieldsStatusOverride::getStatusFieldName($task['itemtype']);
                if (!array_key_exists($statusField, $parent->fields)) { throw new \RuntimeException('Created status unavailable.'); }
                $overrides = \PluginFieldsStatusOverride::getOverridesForItemtypeAndStatus($containerId, $task['itemtype'], (int) $parent->fields[$statusField]);
                foreach ($overrides as $override) {
                    if (empty($override['mandatory'])) { continue; }
                    foreach ($container['definitions'] as $definition) {
                        if ((int) $definition['id'] !== (int) $override['plugin_fields_fields_id'] || $definition['type'] === 'header') { continue; }
                        if (!array_key_exists($definition['name'], $container['values'])
                            || ($definition['type'] !== 'yesno' && empty($container['values'][$definition['name']]))) {
                            throw new \RuntimeException('Created status requires an unavailable mandatory field.');
                        }
                    }
                }
                if ($container['values'] === []) { continue; }
                $data = ['items_id' => $id, 'itemtype' => $task['itemtype'], 'entities_id' => $task['entity_id'],
                    'plugin_fields_containers_id' => $containerId] + $container['values'];
                $model = new \PluginFieldsContainer();
                if (!$model->updateFieldsValues($data, $task['itemtype'], false)) { throw new \RuntimeException('Fields creation failed.'); }
            }
            $snapshot = TaskExecutor::snapshot($target, 4);
            if (TaskExecutor::identity($snapshot) !== $task['identity']) { throw new \RuntimeException('Created identity readback failed.'); }
            foreach ($plan['fields'] as $key => $field) {
                $value = $task['seed'][$key]['value'] ?? ($field['value'] ?? null);
                if ($value !== null && ($snapshot['fields'][$key]['value'] ?? null) !== $value) {
                    throw new \RuntimeException('Created field readback failed.');
                }
            }
            $creationHistory = 0;
            $fieldHistory = [];
            $logs = iterator_to_array($DB->request(['FROM' => 'glpi_logs', 'WHERE' => ['itemtype' => $task['itemtype'], 'items_id' => $id], 'LIMIT' => 101]));
            if (count($logs) > 100) { throw new \RuntimeException('Creation History budget exceeded.'); }
            foreach ($logs as $log) {
                if ($log['user_name'] !== \User::getNameForLog((int) \Session::getLoginUserID())) { continue; }
                if ((int) $log['linked_action'] === \Log::HISTORY_CREATE_ITEM && (int) $log['id_search_option'] === 0
                    && in_array($log['itemtype_link'], ['', '0'], true)) { $creationHistory = (int) $log['id']; }
                foreach ($plan['fields'] as $key => $field) {
                    if (!isset($field['container']) || empty($field['value'])) { continue; }
                    $expected = TaskExecutor::historyValue($field['value'], $field['type']);
                    if ((int) $log['id_search_option'] === $snapshot['fields'][$key]['option']
                        && (int) $log['linked_action'] === 0 && $log['itemtype_link'] === ''
                        && $log['new_value'] === $expected) { $fieldHistory[$key] = (int) $log['id']; }
                }
            }
            if ($creationHistory <= 0) { throw new \RuntimeException('Creation History missing.'); }
            foreach ($plan['fields'] as $key => $field) {
                if (isset($field['container']) && !empty($field['value']) && !isset($fieldHistory[$key])) {
                    throw new \RuntimeException('Custom creation History missing.');
                }
            }
            $result = ['outcome' => 'verified', 'changed' => [], 'created_id' => $id, 'creation_history' => $creationHistory,
                'field_history' => $fieldHistory, 'snapshot' => $snapshot];
            $encoded = json_encode($result, JSON_THROW_ON_ERROR);
            if (strlen($encoded) > 49152) { throw new \RuntimeException('Creation result exceeds transport budget.'); }
            TaskExecutor::validate($task, $settings);
            TaskExecutor::configuration($settings);
            // Detect an unexpected implicit commit by a model/plugin before claiming atomic success.
            Outbox::sql('RELEASE SAVEPOINT assetsync_create');
            if (!$DB->update(TaskJournal::TABLE, ['state' => 'verified', 'result' => $encoded], ['id' => $task['id'], 'hash' => $task['hash'], 'result' => '{}'])
                || $DB->affectedRows() !== 1) { throw new \RuntimeException('Creation result could not be retained.'); }
            Outbox::sql('COMMIT');
            return $result;
        } catch (\Throwable) {
            try { $DB->doQuery('ROLLBACK'); } catch (\Throwable) { /* A lost transaction is uncertain. */ }
            $result = ['outcome' => $attempted ? 'uncertain' : 'blocked', 'changed' => []];
            // An extension may have committed before the savepoint check. Keep the returned ID,
            // without claiming that the parent survived rollback or that creation was verified.
            if (is_int($id) && $id > 0) { $result['attempted_id'] = $id; }
            return $result;
        }
    }
}
