<?php

declare(strict_types=1);

namespace GlpiPlugin\Assetsync20agent;

final class TaskExecutor
{
    public static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    public static function validate(array $task, array $settings, bool $execution = true): void
    {
        $hash = $task['hash'] ?? '';
        unset($task['hash']);
        $keys = ['version', 'id', 'kind', 'installation', 'registration', 'generation', 'connection_id', 'computer_id', 'entity_id', 'serial', 'policy', 'expires', 'changes'];
        if (in_array($task['version'] ?? null, [3, 4, 5, 6], true)) {
            $keys = array_values(array_diff($keys, ['computer_id']));
            $keys = array_merge($keys, ['pair_id', 'itemtype', 'items_id']);
            if (($task['kind'] ?? '') === 'discover') { $keys[] = 'filter'; }
        }
        if (in_array($task['version'] ?? null, [4, 5, 6], true)) { $keys[] = 'identity'; }
        if (($task['version'] ?? null) === 6) { $keys[] = 'guard'; }
        if (($task['version'] ?? null) === 5) { $keys = array_merge($keys, ['seed', 'prerequisites']); }
        if (array_diff(array_keys($task), $keys) !== [] || array_diff($keys, array_keys($task)) !== []) {
            throw new \RuntimeException('Unknown task schema.');
        }
        if (!is_string($hash) || !hash_equals(self::hash($task), $hash)
            || !in_array($task['version'] ?? null, [1, 2, 3, 4, 5, 6], true) || !preg_match('/^[a-f0-9]{32}$/D', $task['id'] ?? '')
            || !in_array($task['kind'] ?? '', $task['version'] === 5 ? ['prepare_create', 'create', 'confirm_create']
                : ($task['version'] === 4 ? ['inspect', 'apply', 'match', 'reverify']
                : ($task['version'] === 3 ? ['inspect', 'apply', 'discover'] : ['inspect', 'apply'])), true)
            || !is_int($task['expires'] ?? null) || ($execution && ($task['expires'] < time()
            || $task['expires'] > time() + 3600)) || !is_array($task['changes'] ?? null)
            || count($task['changes']) > 32) {
            throw new \RuntimeException('Invalid or expired task.');
        }
        foreach (['installation', 'registration', 'generation', 'connection_id'] as $key) {
            if (!is_string($task[$key] ?? null) || $task[$key] !== ($settings[$key] ?? null)) {
                throw new \RuntimeException('Task binding mismatch.');
            }
        }
        if (!is_int($task['entity_id'] ?? null) || $task['entity_id'] < ($task['kind'] === 'discover' ? -1 : 0) || !is_string($task['serial'] ?? null)
            || ($task['kind'] !== 'apply' && $task['changes'] !== [])) {
            throw new \RuntimeException('Task target mismatch.');
        }
        if ($task['version'] >= 4 && !($task['version'] === 6 && $task['kind'] === 'inspect' && $task['identity'] === null)
            && (!is_array($task['identity']) || self::identity($task['identity']) !== $task['identity'])) {
            throw new \RuntimeException('Invalid v4 identity guard.');
        }
        if ($task['version'] === 6) {
            if (!in_array($task['kind'], ['inspect', 'apply'], true) || ($task['kind'] === 'inspect' ? $task['guard'] !== null
                : (!is_array($task['guard']) || array_keys($task['guard']) !== ['modification', 'native_values', 'origin_epoch']
                    || !is_array($task['guard']['modification']) || !is_array($task['guard']['native_values'])
                    || array_keys($task['guard']['native_values']) !== ['name', 'comment']
                    || ($task['guard']['origin_epoch'] !== null && (!is_int($task['guard']['origin_epoch']) || $task['guard']['origin_epoch'] <= 0))))) {
                throw new \RuntimeException('Invalid v6 guard.');
            }
            if ($task['kind'] === 'apply') {
                $mod = $task['guard']['modification'];
                if (array_keys($mod) !== ['epoch', 'untrusted_date_mod', 'history'] || ($mod['untrusted_date_mod'] !== null && !is_string($mod['untrusted_date_mod']))
                    || !is_array($mod['history'])
                    || array_keys($mod['history']) !== ['id', 'epoch', 'field'] || !is_int($mod['history']['id']) || $mod['history']['id'] < 0
                    || !in_array($mod['history']['field'], ['', 'name', 'comment'], true)) { throw new \RuntimeException('Invalid native History guard.'); }
                foreach ([$mod['epoch'], $mod['history']['epoch'], $task['guard']['origin_epoch']] as $epoch) {
                    if ($epoch !== null && (!is_int($epoch) || $epoch <= 0 || $epoch > time() + 30)) { throw new \RuntimeException('Invalid native instant guard.'); }
                }
                foreach ($task['guard']['native_values'] as $value) {
                    if (!is_string($value) || strlen($value) > 16384) { throw new \RuntimeException('Invalid native value guard.'); }
                }
            }
        }
        if ($task['version'] >= 3) {
            TargetScope::model($task['itemtype']);
            if ($task['version'] === 5) {
                Creation::validate($task);
            } elseif (in_array($task['kind'], ['match', 'reverify'], true)) {
                if ($task['pair_id'] !== '' || !is_int($task['items_id'])
                    || ($task['kind'] === 'match' ? $task['items_id'] !== 0 : $task['items_id'] <= 0)
                    || $task['serial'] !== '' || $task['identity'] === ['serial' => '', 'otherserial' => '']) {
                    throw new \RuntimeException('Invalid read-only match target.');
                }
            } elseif ($task['kind'] === 'discover') {
                if ($task['pair_id'] !== '' || $task['items_id'] !== 0 || !is_array($task['filter'])
                    || array_keys($task['filter']) !== ['search', 'cursor'] || !is_string($task['filter']['search'])
                    || strlen($task['filter']['search']) > 100 || !is_int($task['filter']['cursor']) || $task['filter']['cursor'] < 0) {
                    throw new \RuntimeException('Invalid discovery filter.');
                }
            } elseif (!is_string($task['pair_id']) || !preg_match('/^[a-f0-9]{32}$/D', $task['pair_id'])
                || !is_int($task['items_id']) || $task['items_id'] <= 0) { throw new \RuntimeException('Invalid typed pair.'); }
        } elseif ($execution && (($task['computer_id'] ?? null) !== (int) ($settings['computer_id'] ?? 0)
            || $task['entity_id'] !== (int) ($settings['entity_id'] ?? -1))) {
            throw new \RuntimeException('Legacy task target mismatch.');
        }
        foreach ($task['changes'] as $key => $change) {
            $keys = $task['version'] === 1 ? ['metadata', 'expected', 'value'] : ['type', 'metadata', 'expected', 'value'];
            if (!is_string($key) || !is_array($change) || array_keys($change) !== $keys || !is_string($change['metadata'])) {
                throw new \RuntimeException('Invalid change schema.');
            }
            $type = $task['version'] === 1 ? 'text' : $change['type'];
            if (!is_string($type) || self::normalizeValue($type, $change['expected']) !== $change['expected']
                || self::normalizeValue($type, $change['value'], true) !== $change['value']
                || (is_string($change['value']) && strlen($change['value']) > 16384)
                || (is_string($change['expected']) && strlen($change['expected']) > 16384)
                || (in_array($key, ['name', 'comment'], true) && $type !== 'text')) {
                throw new \RuntimeException('Invalid scalar change.');
            }
        }
    }

    /** Independently packaged v4 normalization; never used for retained v1-v3 tasks. */
    public static function identity(array $row): array
    {
        $identity = [];
        foreach (['serial', 'otherserial'] as $key) {
            if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))
                || strlen((string) $row[$key]) > 255 || preg_match('/[\x00-\x1f\x7f]/', (string) $row[$key])) {
                throw new \RuntimeException('Native identity is missing or invalid.');
            }
            $identity[$key] = trim((string) $row[$key], ' ');
        }
        return $identity;
    }

    /** Independently packaged equivalent of A's strict agent normalization. */
    public static function normalizeValue(string $type, mixed $value, bool $authoritative = false): string|int
    {
        if ($type === 'text' && ($value === null || is_scalar($value))) { return (string) $value; }
        if ($type === 'yesno') {
            if (in_array($value, [0, 1, '0', '1'], true)) { return (int) $value; }
            if (!$authoritative && ($value === null || $value === '')) { return ''; }
        }
        if ($type === 'date') {
            if ($value === null || $value === '') { return ''; }
            if (is_string($value) && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)
                && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) { return $value; }
        }
        throw new \RuntimeException('Unsupported agent scalar value.');
    }

    /** Keys and generated model classes come only from fresh local official metadata. */
    public static function snapshot(array $settings, int $version = 2): array
    {
        global $DB;
        $type = $settings['itemtype'] ?? 'Computer';
        $model = TargetScope::model($type);
        $table = $model::getTable();
        \Glpi\Search\SearchOption::clearSearchOptionCache($type);
        $computer = ServiceContext::computer($settings);
        $id = (int) ($settings['items_id'] ?? $settings['computer_id']);
        $native = $version === 6 ? self::nativeEvidence($settings) : null;
        if ($native !== null) { $computer->fields = $native['asset']; }
        $options = \Glpi\Search\SearchOption::getOptionsForItemtype($type);
        $fields = [];
        foreach (['name', 'comment'] as $column) {
            $matches = array_filter($options, static fn ($option): bool => is_array($option)
                && ($option['table'] ?? '') === $table && ($option['field'] ?? '') === $column);
            if (count($matches) !== 1) {
                throw new \RuntimeException('Native metadata is ambiguous.');
            }
            $optionId = (int) array_key_first($matches);
            $option = $matches[$optionId];
            $fields[$column] = ['key' => $column, 'label' => $option['name'], 'type' => 'text', 'group' => 'Native',
                'option' => $optionId, 'metadata' => self::hash([$column, $option]), 'value' => (string) ($computer->fields[$column] ?? '')];
        }
        if (\Plugin::isPluginActive('fields')) {
            foreach (Snapshot::containers($type) as $container) {
                if (in_array($version, [4, 6], true) && (int) $container['entities_id'] === (int) $settings['entity_id'] && !$container['is_recursive']
                    && \PluginFieldsProfile::getRightOnContainer((int) $settings['service_profile'], (int) $container['id']) <= READ) {
                    throw new \RuntimeException('V4 scoped Fields permission unavailable.');
                }
                if ((int) $container['entities_id'] !== (int) $settings['entity_id'] || $container['is_recursive']
                    || \PluginFieldsProfile::getRightOnContainer((int) $settings['service_profile'], (int) $container['id']) <= READ
                    || !$computer->can($id, UPDATE)) {
                    continue;
                }
                $definitions = iterator_to_array($DB->request(['FROM' => 'glpi_plugin_fields_fields', 'WHERE' => [
                    'plugin_fields_containers_id' => (int) $container['id'], 'is_active' => 1,
                    'type' => $version === 1 ? 'text' : ['text', 'yesno', 'date']], 'LIMIT' => 33]));
                if ($definitions === []) { continue; }
                $children = iterator_to_array($DB->request(['FROM' => $container['table'], 'WHERE' => ['items_id' => $id], 'LIMIT' => 2]));
                if (count($children) !== 1) {
                    if ($version >= 2) { throw new \RuntimeException('Exactly one existing Fields row is required.'); }
                    continue;
                }
                $child = array_values($children)[0];
                if (($child['itemtype'] ?? '') !== $type || (int) ($child['plugin_fields_containers_id'] ?? 0) !== (int) $container['id']) {
                    if ($version >= 2) { throw new \RuntimeException('Fields row identity changed.'); }
                    continue;
                }
                foreach ($definitions as $field) {
                    $column = $field['name'];
                    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $column) || !array_key_exists($column, $child)) {
                        if ($version >= 2) { throw new \RuntimeException('Fields column is unavailable.'); }
                        continue;
                    }
                    $matches = array_filter($options, static fn ($option): bool => is_array($option)
                        && ($option['table'] ?? '') === $container['table'] && ($option['field'] ?? '') === $column);
                    if (count($matches) !== 1) {
                        if ($version >= 2) { throw new \RuntimeException('Fields metadata is missing or ambiguous.'); }
                        continue;
                    }
                    $option = reset($matches);
                    if (($field['type'] === 'yesno' && ($option['datatype'] ?? '') !== 'bool')
                        || ($field['type'] === 'date' && ($option['datatype'] ?? '') !== 'date')) {
                        throw new \RuntimeException('Fields definition and search type disagree.');
                    }
                    $key = $type . '.' . $container['class'] . '.' . $column;
                    $fields[$key] = ['key' => $key, 'label' => $version === 1 ? $container['label'] . ' - ' . $field['label'] : $option['name'],
                        'type' => $field['type'], 'group' => 'Custom', 'option' => (int) array_key_first($matches),
                        'container' => (int) $container['id'], 'field' => (int) $field['id'], 'child' => (int) $child['id'],
                        'metadata' => self::hash([$container, $field, (int) $child['id'], $matches]),
                        'value' => self::normalizeValue($field['type'], $child[$column])];
                    if (count($fields) > 32) {
                        throw new \RuntimeException('Scoped metadata budget exceeded.');
                    }
                }
            }
        }
        $snapshot = ['computer_id' => $id, 'entity_id' => (int) $computer->fields['entities_id'],
            'serial' => trim((string) $computer->fields['serial']), 'fields' => $fields, 'observed_at' => time()];
        if ($version >= 3) {
            unset($snapshot['computer_id']);
            $snapshot['itemtype'] = $type;
            $snapshot['items_id'] = $id;
            $snapshot['pair_id'] = $settings['pair_id'];
            $snapshot['name'] = (string) $computer->fields['name'];
        }
        if (in_array($version, [4, 6], true)) { $snapshot = array_replace($snapshot, self::identity($computer->fields)); }
        if ($version === 6) {
            $snapshot['modification'] = $native['modification'];
            $snapshot['native_values'] = $native['native_values'];
            $snapshot['origin_epoch'] = self::nativeOrigin($settings, $native);
        }
        if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > 49152) {
            throw new \RuntimeException('Scoped snapshot exceeds the transport budget.');
        }
        return $snapshot;
    }

    public static function execute(array $task, array $settings): array
    {
        $changed = [];
        $attempted = false;
        try {
            self::validate($task, $settings);
            if ($task['version'] >= 3) {
                $settings = array_replace($settings, array_intersect_key($task, array_flip(['itemtype', 'items_id', 'pair_id', 'entity_id'])));
                if ($task['kind'] === 'discover' && $task['entity_id'] === -1) {
                    $entities = Settings::entities((int) $settings['service_user'], (int) $settings['service_profile']);
                    if ($entities === []) { throw new \RuntimeException('No permitted exact entity.'); }
                    $settings['entity_id'] = (int) array_key_first($entities);
                }
            }
            self::configuration($settings, $task['version'] < 3);
            return ServiceContext::run($settings, static function () use ($task, $settings, &$changed, &$attempted): array {
                if ($task['version'] === 5) { return Creation::execute($task, $settings); }
                if ($task['version'] === 6) { return self::executeBoth($task, $settings); }
                if ($task['kind'] === 'discover') {
                    return ['outcome' => 'unchanged', 'changed' => [], 'discovery' => self::discover($task, $settings)];
                }
                if (in_array($task['kind'], ['match', 'reverify'], true)) {
                    $evidence = self::matchEvidence($task, $settings);
                    $result = ['outcome' => 'unchanged', 'changed' => [], 'evidence' => $evidence];
                    if ($task['kind'] === 'reverify') { $result['snapshot'] = self::snapshot($settings, 4); }
                    if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 49152) {
                        throw new \RuntimeException('Matching read budget exceeded.');
                    }
                    return $result;
                }
                $type = $settings['itemtype'] ?? 'Computer';
                $id = (int) ($settings['items_id'] ?? $settings['computer_id']);
                $before = self::snapshot($settings, $task['version']);
                if (($task['serial'] !== '' && $before['serial'] !== $task['serial'])
                    || ($task['version'] === 4 && self::identity($before) !== $task['identity'])) {
                    return ['outcome' => 'stale', 'changed' => []];
                }
                foreach ($task['changes'] as $key => $change) {
                    $field = $before['fields'][$key] ?? null;
                    if (!$field || $field['type'] !== ($change['type'] ?? 'text')
                        || $field['metadata'] !== $change['metadata'] || $field['value'] !== $change['expected']) {
                        return ['outcome' => 'stale', 'changed' => []];
                    }
                }
                foreach ($task['changes'] as $key => $change) {
                    // Re-authorize and re-read before each model write. This does not claim a human-edit lock.
                    self::validate($task, $settings);
                    self::configuration($settings, $task['version'] < 3);
                    // InnoDB row/range locks keep the existing parent and child present through the model call.
                    // Official Fields 1.24.2/1.24.4/1.24.5 do not start or commit a transaction here.
                    global $DB;
                    Outbox::sql('START TRANSACTION');
                    try {
                    self::lockRows($settings);
                    $fresh = self::snapshot($settings, $task['version']);
                    $field = $fresh['fields'][$key] ?? null;
                    if (!$field || $field['type'] !== ($change['type'] ?? 'text')
                        || $fresh['serial'] !== $before['serial'] || ($task['version'] === 4 && self::identity($fresh) !== $task['identity']) || $field['metadata'] !== $change['metadata']
                        || $field['value'] !== $change['expected']) {
                        Outbox::sql('ROLLBACK');
                        return ['outcome' => $changed === [] ? 'stale' : 'partial', 'changed' => $changed];
                    }
                    if ($field['value'] === $change['value']) {
                        Outbox::sql('COMMIT');
                        continue;
                    }
                    $computer = ServiceContext::computer($settings);
                    $history = \Log::getHistoryData($computer, 0, 100);
                    if (in_array($key, ['name', 'comment'], true)) {
                        $attempted = true;
                        $ok = $computer->update(['id' => $id, $key => $change['value']]);
                    } else {
                        $container = new \PluginFieldsContainer();
                        $definition = new \PluginFieldsField();
                        if (!$container->getFromDB($field['container']) || !$definition->getFromDB($field['field'])) {
                            throw new \RuntimeException('Fields metadata disappeared.');
                        }
                        if ($field['type'] === 'date' && $change['value'] === '') {
                            $mandatory = !empty($definition->fields['mandatory']);
                            $statusField = \PluginFieldsStatusOverride::getStatusFieldName($type);
                            foreach (\PluginFieldsStatusOverride::getOverridesForItemtypeAndStatus($field['container'], $type,
                                (int) $computer->fields[$statusField]) as $override) {
                                if ((int) $override['plugin_fields_fields_id'] === $field['field']) { $mandatory = !empty($override['mandatory']); }
                            }
                            // These Fields versions also enforce the base flag in their model validator.
                            if ($mandatory || !empty($definition->fields['mandatory'])) {
                                throw new \RuntimeException('Mandatory date cannot be cleared.');
                            }
                        }
                        // GLPI loosely compares NULL and integer zero; string "0" forces the real update.
                        $value = $field['type'] === 'yesno' && $change['value'] === 0 ? '0' : $change['value'];
                        $attempted = true;
                        $ok = $container->updateFieldsValues(['items_id' => $id, 'itemtype' => $type,
                            'plugin_fields_containers_id' => $field['container'], $definition->fields['name'] => $value], $type, false);
                    }
                    $after = self::snapshot($settings, $task['version']);
                    if (!$ok || ($task['version'] === 4 && self::identity($after) !== $task['identity']) || ($after['fields'][$key]['value'] ?? null) !== $change['value']
                        || ($after['fields'][$key]['metadata'] ?? null) !== $field['metadata']) {
                        throw new \RuntimeException('Model readback failed.');
                    }
                    $newHistory = array_filter(\Log::getHistoryData($computer, 0, 100), static fn ($row): bool => !in_array($row['id'], array_column($history, 'id'), true));
                    $named = array_filter($newHistory, static fn ($row): bool => $row['field'] === $field['label']
                        && $row['user_name'] === \User::getNameForLog((int) \Session::getLoginUserID()));
                    if (count($named) !== 1) {
                        throw new \RuntimeException('Named parent history missing.');
                    }
                    $log = Outbox::one('SELECT * FROM `glpi_logs` WHERE `id` = ' . (int) array_values($named)[0]['id']);
                    // Generated Fields models may retain blank; date-aware models use the SQL NULL sentinel.
                    $newHistoryValues = $field['type'] === 'date' && $change['value'] === ''
                        ? ['', 'NULL'] : [self::historyValue($change['value'], $field['type'])];
                    // GLPI renders Comments as "Update of the field"; compare official raw audit values.
                    if (!$log || (int) $log['id_search_option'] !== $field['option'] || $log['itemtype'] !== $type
                        || (int) $log['items_id'] !== $id || $log['itemtype_link'] !== '' || (int) $log['linked_action'] !== 0
                        || $log['old_value'] !== self::historyValue($field['value'], $field['type'])
                        || !in_array($log['new_value'], $newHistoryValues, true)) {
                        throw new \RuntimeException('Named parent history values mismatch.');
                    }
                    Outbox::sql('COMMIT');
                    $changed[$key] = (int) array_values($named)[0]['id'];
                    $attempted = false;
                    } catch (\Throwable $error) {
                        $DB->doQuery('ROLLBACK');
                        throw $error;
                    }
                }
                return ['outcome' => $changed === [] ? 'unchanged' : 'verified', 'changed' => $changed, 'snapshot' => self::snapshot($settings, $task['version'])];
            });
        } catch (\Throwable) {
            return ['outcome' => $attempted ? 'uncertain' : ($changed !== [] ? 'partial' : 'blocked'), 'changed' => $changed];
        }
    }

    private static function nativeEvidence(array $settings): array
    {
        global $DB;
        $type = $settings['itemtype'];
        $id = (int) $settings['items_id'];
        $table = TargetScope::model($type)::getTable();
        $schema = Outbox::one('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
            . $DB->quote($table) . ' AND COLUMN_NAME = \'date_mod\'');
        $row = Outbox::one('SELECT *, UNIX_TIMESTAMP(`date_mod`) AS epoch, (SELECT COALESCE(MAX(`id`),0) FROM `glpi_logs` WHERE `itemtype` = '
            . $DB->quote($type) . ' AND `items_id` = ' . $id . ') AS history_id FROM `' . $table . '` WHERE `id` = ' . $id);
        if (!$row || (int) $row['entities_id'] !== (int) $settings['entity_id'] || !empty($row['is_deleted']) || !empty($row['is_template'])) {
            throw new \RuntimeException('Native comparison target changed.');
        }
        $epoch = strtolower((string) ($schema['DATA_TYPE'] ?? '')) === 'timestamp' && is_numeric($row['epoch'])
            && (int) $row['epoch'] > 0 && (int) $row['epoch'] <= time() + 30 ? (int) $row['epoch'] : null;
        $log = Outbox::one('SELECT *, UNIX_TIMESTAMP(`date_mod`) AS epoch FROM `glpi_logs` WHERE `id` = ' . (int) $row['history_id']);
        $field = '';
        $options = \Glpi\Search\SearchOption::getOptionsForItemtype($type);
        $dateOptions = array_filter($options, static fn ($option): bool => is_array($option) && ($option['table'] ?? '') === $table && ($option['field'] ?? '') === 'date_mod');
        if ($log && $epoch !== null && count($dateOptions) === 1 && (int) $log['id_search_option'] === (int) array_key_first($dateOptions)
            && $log['itemtype_link'] === '' && (int) $log['linked_action'] === 0 && (int) $log['epoch'] === $epoch
            && self::nativeTimestampLog($log, $epoch)) {
            // GLPI may append Last update after the real field. Keep the newest ID as the guard.
            $previous = Outbox::one('SELECT *, UNIX_TIMESTAMP(`date_mod`) AS epoch FROM `glpi_logs` WHERE `itemtype` = '
                . $DB->quote($type) . ' AND `items_id` = ' . $id . ' AND `id` < ' . (int) $row['history_id'] . ' ORDER BY `id` DESC LIMIT 1');
            if ($previous && (int) $previous['epoch'] === $epoch && $previous['user_name'] === $log['user_name']) { $log = $previous; }
        }
        foreach ($options as $optionId => $option) {
            $key = $option['field'] ?? '';
            if (is_array($option) && ($option['table'] ?? '') === $table && in_array($key, ['name', 'comment'], true)
                && $log && (int) $log['id_search_option'] === (int) $optionId && $log['itemtype_link'] === ''
                && (int) $log['linked_action'] === 0 && $log['new_value'] === self::historyValue((string) $row[$key], 'text')) { $field = $key; }
        }
        return ['asset' => $row, 'history_row' => $log, 'native_values' => ['name' => (string) $row['name'], 'comment' => (string) $row['comment']],
            'modification' => ['epoch' => $epoch, 'untrusted_date_mod' => $epoch === null ? $row['date_mod'] : null, 'history' => ['id' => (int) $row['history_id'],
                'epoch' => is_numeric($log['epoch'] ?? null) && (int) $log['epoch'] > 0 && (int) $log['epoch'] <= time() + 30 ? (int) $log['epoch'] : null, 'field' => $field]]];
    }

    private static function nativeTimestampLog(array $log, int $epoch): bool
    {
        if (!preg_match('/\(([1-9][0-9]*)\)$/', $log['user_name'], $match)
            || \User::getNameForLog((int) $match[1]) !== $log['user_name']) { return false; }
        $user = new \User();
        if (!$user->getFromDB((int) $match[1])) { return false; }
        $zone = $user->fields['timezone'] ?? (\Config::getConfigurationValues('core')['timezone'] ?? '');
        if ($zone === '' || $zone === '0') { return false; }
        try {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $log['new_value'], new \DateTimeZone($zone));
            return $date !== false && $date->format('Y-m-d H:i:s') === $log['new_value'] && $date->getTimestamp() === $epoch
                && $log['old_value'] !== $log['new_value'];
        } catch (\Exception) { return false; }
    }

    private static function nativeOrigin(array $settings, array $native): ?int
    {
        global $DB;
        $latestOwn = 0;
        $possibleOwn = false;
        $rows = $DB->request(['FROM' => TaskJournal::TABLE, 'WHERE' => ['installation' => $settings['installation'],
            'connection_id' => $settings['connection_id'], new \Glpi\DBAL\QueryExpression('(JSON_EXTRACT(`body`, \'$.items_id\') = ' . (int) $settings['items_id']
                . ' OR JSON_EXTRACT(`body`, \'$.computer_id\') = ' . (int) $settings['items_id'] . ') AND '
                . '(JSON_EXTRACT(`result`, \'$.mutation\') IS NOT NULL OR JSON_EXTRACT(`result`, \'$.changed\') <> \'[]\''
                . ' OR `state` IN (\'executing\',\'partial\',\'uncertain\'))')], 'ORDER' => 'created_at DESC,id DESC', 'LIMIT' => 32]);
        foreach ($rows as $row) {
            $body = json_decode($row['body'], true, 32, JSON_THROW_ON_ERROR);
            if (($body['itemtype'] ?? 'Computer') !== $settings['itemtype']) { continue; }
            $result = json_decode($row['result'], true, 32, JSON_THROW_ON_ERROR);
            $anchor = $result['mutation'] ?? null;
            if ($anchor && $anchor['modification'] === $native['modification'] && $anchor['native_values'] === $native['native_values']) { return $anchor['origin_epoch']; }
            foreach ($result['changed'] ?? [] as $historyId) { $latestOwn = max($latestOwn, $historyId); }
            if ($anchor) { $latestOwn = max($latestOwn, $anchor['modification']['history']['id']); }
            $key = $native['modification']['history']['field'];
            $change = $body['changes'][$key] ?? null;
            $log = $native['history_row'];
            if (!$anchor && !isset($result['changed'][$key]) && $change && $log
                && $change['value'] === $native['native_values'][$key]
                && $log['old_value'] === self::historyValue($change['expected'], $change['type'] ?? 'text')
                && $log['new_value'] === self::historyValue($change['value'], $change['type'] ?? 'text')) { $possibleOwn = true; }
        }
        $mod = $native['modification'];
        $author = $native['history_row']['user_name'] ?? '';
        return $mod['epoch'] !== null && $mod['history']['epoch'] === $mod['epoch'] && $mod['history']['field'] !== ''
            && !$possibleOwn && $author !== ''
            && ($author !== \User::getNameForLog((int) $settings['service_user']) || $latestOwn > 0)
            && $mod['history']['id'] > $latestOwn ? $mod['epoch'] : null;
    }

    /** V6 validates the entire batch and its timestamp assertion under one parent/child lock. */
    private static function executeBoth(array $task, array $settings): array
    {
        global $DB;
        $before = self::snapshot($settings, 6);
        if (($task['serial'] !== '' && $before['serial'] !== $task['serial'])
            || ($task['identity'] !== null && self::identity($before) !== $task['identity'])) { return ['outcome' => 'stale', 'changed' => []]; }
        if ($task['kind'] === 'inspect') { return ['outcome' => 'unchanged', 'changed' => [], 'snapshot' => $before]; }
        $journal = Outbox::one('SELECT * FROM `' . TaskJournal::TABLE . '` WHERE `id` = ' . $DB->quote($task['id']));
        if (!$journal || $journal['hash'] !== $task['hash'] || $journal['state'] !== 'executing' || $journal['result'] !== '{}') {
            throw new \RuntimeException('V6 requires its durable executing journal.');
        }
        $attempted = false;
        Outbox::sql('START TRANSACTION');
        try {
            self::lockRows($settings);
            $before = self::snapshot($settings, 6);
            if ($before['modification'] !== $task['guard']['modification'] || $before['native_values'] !== $task['guard']['native_values']
                || self::identity($before) !== $task['identity']) {
                Outbox::sql('ROLLBACK');
                return ['outcome' => 'stale', 'changed' => []];
            }
            foreach ($task['changes'] as $key => $change) {
                $field = $before['fields'][$key] ?? null;
                if (!$field || $field['metadata'] !== $change['metadata'] || $field['type'] !== $change['type'] || $field['value'] !== $change['expected']) {
                    Outbox::sql('ROLLBACK');
                    return ['outcome' => 'stale', 'changed' => []];
                }
                if ($field['type'] === 'date' && $change['value'] === '' && $field['value'] !== $change['value']) {
                    $definition = new \PluginFieldsField();
                    if (!$definition->getFromDB($field['field'])) { throw new \RuntimeException('Fields metadata disappeared.'); }
                    $computer = ServiceContext::computer($settings);
                    $mandatory = !empty($definition->fields['mandatory']);
                    $statusField = \PluginFieldsStatusOverride::getStatusFieldName($settings['itemtype']);
                    foreach (\PluginFieldsStatusOverride::getOverridesForItemtypeAndStatus($field['container'], $settings['itemtype'],
                        (int) $computer->fields[$statusField]) as $override) {
                        if ((int) $override['plugin_fields_fields_id'] === $field['field']) { $mandatory = $mandatory || !empty($override['mandatory']); }
                    }
                    if ($mandatory) { throw new \RuntimeException('Mandatory date cannot be cleared.'); }
                }
            }
            $changed = [];
            foreach ($task['changes'] as $key => $change) {
                self::validate($task, $settings);
                self::configuration($settings);
                $fresh = self::snapshot($settings, 6);
                $field = $fresh['fields'][$key] ?? null;
                if (!$field || $field['metadata'] !== $change['metadata'] || $field['value'] !== $change['expected']
                    || self::identity($fresh) !== $task['identity']) { throw new \RuntimeException('Batch expected field changed.'); }
                if ($field['value'] === $change['value']) { continue; }
                $computer = ServiceContext::computer($settings);
                $history = \Log::getHistoryData($computer, 0, 100);
                $attempted = true;
                if (in_array($key, ['name', 'comment'], true)) {
                    $ok = $computer->update(['id' => (int) $settings['items_id'], $key => $change['value']]);
                } else {
                    $container = new \PluginFieldsContainer();
                    $definition = new \PluginFieldsField();
                    if (!$container->getFromDB($field['container']) || !$definition->getFromDB($field['field'])) { throw new \RuntimeException('Fields metadata disappeared.'); }
                    $value = $field['type'] === 'yesno' && $change['value'] === 0 ? '0' : $change['value'];
                    $ok = $container->updateFieldsValues(['items_id' => (int) $settings['items_id'], 'itemtype' => $settings['itemtype'],
                        'plugin_fields_containers_id' => $field['container'], $definition->fields['name'] => $value], $settings['itemtype'], false);
                }
                $after = self::snapshot($settings, 6);
                if (!$ok || self::identity($after) !== $task['identity'] || ($after['fields'][$key]['value'] ?? null) !== $change['value']
                    || ($after['fields'][$key]['metadata'] ?? null) !== $field['metadata']) { throw new \RuntimeException('Model readback failed.'); }
                $named = array_filter(\Log::getHistoryData($computer, 0, 100), static fn ($row): bool => !in_array($row['id'], array_column($history, 'id'), true)
                    && $row['field'] === $field['label'] && $row['user_name'] === \User::getNameForLog((int) \Session::getLoginUserID()));
                if (count($named) !== 1) { throw new \RuntimeException('Named parent history missing.'); }
                $log = Outbox::one('SELECT * FROM `glpi_logs` WHERE `id` = ' . (int) array_values($named)[0]['id']);
                $newValues = $field['type'] === 'date' && $change['value'] === '' ? ['', 'NULL'] : [self::historyValue($change['value'], $field['type'])];
                if (!$log || $log['itemtype'] !== $settings['itemtype'] || (int) $log['items_id'] !== (int) $settings['items_id']
                    || (int) $log['id_search_option'] !== $field['option'] || $log['itemtype_link'] !== '' || (int) $log['linked_action'] !== 0
                    || $log['old_value'] !== self::historyValue($field['value'], $field['type']) || !in_array($log['new_value'], $newValues, true)) {
                    throw new \RuntimeException('Named parent history values mismatch.');
                }
                $changed[$key] = (int) $log['id'];
            }
            $snapshot = self::snapshot($settings, 6);
            $snapshot['origin_epoch'] = $task['guard']['origin_epoch'];
            $result = ['outcome' => $changed === [] ? 'unchanged' : 'verified', 'changed' => $changed, 'snapshot' => $snapshot,
                'mutation' => ['modification' => $snapshot['modification'], 'native_values' => $snapshot['native_values'], 'origin_epoch' => $task['guard']['origin_epoch']]];
            if (!$DB->update(TaskJournal::TABLE, ['state' => $result['outcome'], 'result' => json_encode($result, JSON_THROW_ON_ERROR)],
                ['id' => $task['id'], 'hash' => $task['hash'], 'result' => '{}']) || $DB->affectedRows() !== 1) { throw new \RuntimeException('V6 result storage failed.'); }
            Outbox::sql('COMMIT');
            return $result;
        } catch (\Throwable) {
            try { $DB->doQuery('ROLLBACK'); } catch (\Throwable) { /* The commit reply may already have been lost. */ }
            return ['outcome' => $attempted ? 'uncertain' : 'blocked', 'changed' => []];
        }
    }

    public static function matchEvidence(array $task, array $settings): array
    {
        global $DB;
        // Even a zero-result lookup needs fresh explicit entity and type authorization.
        ServiceContext::account((int) $settings['service_user']);
        ServiceContext::grant($settings);
        $profile = new \Profile();
        if ((int) \Session::getLoginUserID() !== (int) $settings['service_user']
            || \Session::isCron() || \Session::isInventory() || \Session::isRightChecksDisabled()
            || (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) !== (int) $settings['service_profile']
            || (int) ($_SESSION['glpiactive_entity'] ?? -1) !== $task['entity_id']
            || !\Session::haveAccessToEntity($task['entity_id']) || !$profile->getFromDB((int) $settings['service_profile'])
            || ((int) ($profile->fields[strtolower($task['itemtype'])] ?? 0) & (READ | UPDATE)) !== (READ | UPDATE)) {
            throw new \RuntimeException('Native lookup permission unavailable.');
        }
        $model = TargetScope::model($task['itemtype']);
        $table = $model::getTable();
        foreach (['serial', 'otherserial', 'entities_id', 'is_deleted', 'is_template'] as $column) {
            if (!$DB->fieldExists($table, $column)) { throw new \RuntimeException('Native lookup column unavailable.'); }
        }
        $evidence = ['complete' => true, 'observed_at' => time(), 'lookup_complete' => ['serial' => true, 'otherserial' => true],
            'lookup_errors' => ['serial' => '', 'otherserial' => ''], 'serial' => [], 'otherserial' => []];
        $deadline = hrtime(true) + 2_000_000_000;
        foreach (['serial', 'otherserial'] as $column) {
            if ($task['identity'][$column] === '') { continue; }
            try {
            // TRIM without a character argument removes ASCII spaces only in MySQL.
            // Exact byte matching is scoped in SQL before LIMIT; database execution is bounded.
            $result = self::identityQuery('SELECT `id`,`serial`,`otherserial`,`entities_id` FROM `' . $table . '` WHERE `entities_id` = ' . $task['entity_id']
                . ' AND `is_deleted` = 0 AND `is_template` = 0 AND CAST(TRIM(`' . $column . '`) AS BINARY) = CAST('
                . $DB->quote($task['identity'][$column]) . ' AS BINARY) LIMIT 2', $deadline);
            while ($row = $DB->fetchAssoc($result)) {
                $target = array_replace($settings, ['items_id' => (int) $row['id']]);
                $item = ServiceContext::computer($target);
                $identity = self::identity($row);
                if (self::identity($item->fields) !== $identity || $identity[$column] !== $task['identity'][$column]) {
                    throw new \RuntimeException('Native candidate changed during lookup.');
                }
                $evidence[$column][] = ['items_id' => (int) $row['id'], 'itemtype' => $task['itemtype'],
                    'entity_id' => (int) $row['entities_id']] + $identity;
            }
            if (hrtime(true) > $deadline) { throw new \RuntimeException('Native identity read deadline exceeded.'); }
            } catch (\Throwable $error) {
                $evidence[$column] = [];
                $evidence['lookup_complete'][$column] = false;
                $evidence['lookup_errors'][$column] = $error instanceof \UnexpectedValueException
                    ? 'Unsupported database statement timeout.' : 'Identity read failed, timed out, or permission denied.';
            }
        }
        return $evidence;
    }

    private static function identityQuery(string $sql, int $deadline): mixed
    {
        $version = (string) (Outbox::one('SELECT VERSION() AS version')['version'] ?? '');
        $milliseconds = min(1000, (int) (($deadline - hrtime(true)) / 1_000_000));
        if ($milliseconds < 1) { throw new \RuntimeException('Identity read budget exhausted.'); }
        if (stripos($version, 'MariaDB') !== false && preg_match('/(\d+)\.(\d+)\.\d+-MariaDB/i', $version, $parts)
            && ((int) $parts[1] > 10 || ((int) $parts[1] === 10 && (int) $parts[2] >= 6))) {
            return Outbox::sql('SET STATEMENT max_statement_time=' . sprintf('%.3F', $milliseconds / 1000) . ' FOR ' . $sql);
        }
        if (stripos($version, 'MariaDB') === false && preg_match('/^(8|9)\./', $version)) {
            return Outbox::sql('SELECT /*+ MAX_EXECUTION_TIME(' . $milliseconds . ') */ ' . substr($sql, 7));
        }
        throw new \UnexpectedValueException('Unsupported database statement timeout.');
    }

    private static function lockRows(array $settings): void
    {
        global $DB;
        $id = (int) ($settings['items_id'] ?? $settings['computer_id']);
        $type = $settings['itemtype'] ?? 'Computer';
        $model = TargetScope::model($type);
        $parentTable = $model::getTable();
        $tables = [$parentTable];
        $containers = \Plugin::isPluginActive('fields') ? Snapshot::containers($type) : [];
        foreach ($containers as $container) { $tables[] = $container['table']; }
        // Fail closed when locking cannot protect the official update-or-add implementation.
        foreach (array_unique($tables) as $table) {
            $status = Outbox::one('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $DB->quote($table));
            if (strtolower((string) ($status['ENGINE'] ?? '')) !== 'innodb') { throw new \RuntimeException('Transactional asset storage is required.'); }
        }
        Outbox::sql('SELECT `id` FROM `' . $parentTable . '` WHERE `id` = ' . $id . ' FOR UPDATE');
        foreach ($containers as $container) {
            if ((int) $container['entities_id'] === (int) $settings['entity_id']) {
                Outbox::sql('SELECT `id` FROM `' . $container['table'] . '` WHERE `items_id` = ' . $id . ' FOR UPDATE');
            }
        }
    }

    public static function configuration(array $settings, bool $legacy = false): void
    {
        $current = Settings::load();
        $keys = ['mode', 'registration', 'generation', 'installation', 'connection_id',
            'service_user', 'service_profile', 'service_name', 'service_password'];
        if ($legacy) { $keys = array_merge($keys, ['computer_id', 'entity_id']); }
        foreach ($keys as $key) {
            if (($current[$key] ?? null) !== ($settings[$key] ?? null) || ($current['mode'] ?? '') !== 'agent') {
                throw new \RuntimeException('Agent configuration changed before execution.');
            }
        }
    }

    private static function discover(array $task, array $settings): array
    {
        $page = TargetScope::page($task['itemtype'], (int) $settings['entity_id'], $task['filter']['search'], $task['filter']['cursor']);
        foreach ($page['rows'] as &$row) {
            if ($row['reason'] !== '') { continue; }
            try {
                self::snapshot(array_replace($settings, ['items_id' => $row['items_id']]), 3);
            } catch (\Throwable) { $row['reason'] = 'Existing scalar metadata or service grant unavailable.'; }
        }
        unset($row);
        $page['types'] = TargetScope::types();
        $profile = new \Profile();
        $profile->getFromDB((int) $settings['service_profile']);
        foreach ($page['types'] as $type => &$reason) {
            if ($reason === '' && ((int) ($profile->fields[strtolower($type)] ?? 0) & (READ | UPDATE)) !== (READ | UPDATE)) {
                $reason = 'Service profile requires READ and UPDATE permission.';
            }
        }
        unset($reason);
        $page['entities'] = Settings::entities((int) $settings['service_user'], (int) $settings['service_profile']);
        if (strlen(json_encode($page, JSON_THROW_ON_ERROR)) > 49152) { throw new \RuntimeException('Discovery byte limit exceeded.'); }
        return $page;
    }

    public static function historyValue(string|int $value, string $type = 'text'): string
    {
        if ($type === 'yesno') { $value = \Dropdown::getYesNo($value); }
        $decoded = json_decode($value);
        return is_array($decoded) || is_object($decoded) ? '' : mb_substr($value, 0, 255);
    }
}
