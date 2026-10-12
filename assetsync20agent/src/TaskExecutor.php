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
        if (array_diff(array_keys($task), $keys) !== [] || array_diff($keys, array_keys($task)) !== []) {
            throw new \RuntimeException('Unknown task schema.');
        }
        if (!is_string($hash) || !hash_equals(self::hash($task), $hash)
            || !in_array($task['version'] ?? null, [1, 2], true) || !preg_match('/^[a-f0-9]{32}$/D', $task['id'] ?? '')
            || !in_array($task['kind'] ?? '', ['inspect', 'apply'], true)
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
        if (($task['computer_id'] ?? null) !== (int) $settings['computer_id']
            || ($task['entity_id'] ?? null) !== (int) $settings['entity_id']
            || !is_string($task['serial'] ?? null)
            || ($task['kind'] === 'inspect' && $task['changes'] !== [])) {
            throw new \RuntimeException('Task target mismatch.');
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
        \Glpi\Search\SearchOption::clearSearchOptionCache('Computer');
        $computer = ServiceContext::computer($settings);
        $id = (int) $settings['computer_id'];
        $options = \Glpi\Search\SearchOption::getOptionsForItemtype('Computer');
        $fields = [];
        foreach (['name', 'comment'] as $column) {
            $matches = array_filter($options, static fn ($option): bool => is_array($option)
                && ($option['table'] ?? '') === 'glpi_computers' && ($option['field'] ?? '') === $column);
            if (count($matches) !== 1) {
                throw new \RuntimeException('Native metadata is ambiguous.');
            }
            $optionId = (int) array_key_first($matches);
            $option = $matches[$optionId];
            $fields[$column] = ['key' => $column, 'label' => $option['name'], 'type' => 'text', 'group' => 'Native',
                'option' => $optionId, 'metadata' => self::hash([$column, $option]), 'value' => (string) ($computer->fields[$column] ?? '')];
        }
        if (\Plugin::isPluginActive('fields')) {
            foreach (Snapshot::containers() as $container) {
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
                    if ($version === 2) { throw new \RuntimeException('Exactly one existing Fields row is required.'); }
                    continue;
                }
                $child = array_values($children)[0];
                if (($child['itemtype'] ?? '') !== 'Computer' || (int) ($child['plugin_fields_containers_id'] ?? 0) !== (int) $container['id']) {
                    if ($version === 2) { throw new \RuntimeException('Fields row identity changed.'); }
                    continue;
                }
                foreach ($definitions as $field) {
                    $column = $field['name'];
                    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $column) || !array_key_exists($column, $child)) {
                        if ($version === 2) { throw new \RuntimeException('Fields column is unavailable.'); }
                        continue;
                    }
                    $matches = array_filter($options, static fn ($option): bool => is_array($option)
                        && ($option['table'] ?? '') === $container['table'] && ($option['field'] ?? '') === $column);
                    if (count($matches) !== 1) {
                        if ($version === 2) { throw new \RuntimeException('Fields metadata is missing or ambiguous.'); }
                        continue;
                    }
                    $option = reset($matches);
                    if (($field['type'] === 'yesno' && ($option['datatype'] ?? '') !== 'bool')
                        || ($field['type'] === 'date' && ($option['datatype'] ?? '') !== 'date')) {
                        throw new \RuntimeException('Fields definition and search type disagree.');
                    }
                    $key = 'Computer.' . $container['class'] . '.' . $column;
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
            self::configuration($settings);
            return ServiceContext::run($settings, static function () use ($task, $settings, &$changed, &$attempted): array {
                $before = self::snapshot($settings, $task['version']);
                if ($task['serial'] !== '' && $before['serial'] !== $task['serial']) {
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
                    self::configuration($settings);
                    // InnoDB row/range locks keep the existing parent and child present through the model call.
                    // Official Fields 1.24.2/1.24.4/1.24.5 do not start or commit a transaction here.
                    global $DB;
                    Outbox::sql('START TRANSACTION');
                    try {
                    self::lockRows($settings);
                    $fresh = self::snapshot($settings, $task['version']);
                    $field = $fresh['fields'][$key] ?? null;
                    if (!$field || $field['type'] !== ($change['type'] ?? 'text')
                        || $fresh['serial'] !== $before['serial'] || $field['metadata'] !== $change['metadata']
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
                        $ok = $computer->update(['id' => $task['computer_id'], $key => $change['value']]);
                    } else {
                        $container = new \PluginFieldsContainer();
                        $definition = new \PluginFieldsField();
                        if (!$container->getFromDB($field['container']) || !$definition->getFromDB($field['field'])) {
                            throw new \RuntimeException('Fields metadata disappeared.');
                        }
                        if ($field['type'] === 'date' && $change['value'] === '') {
                            $mandatory = !empty($definition->fields['mandatory']);
                            $statusField = \PluginFieldsStatusOverride::getStatusFieldName('Computer');
                            foreach (\PluginFieldsStatusOverride::getOverridesForItemtypeAndStatus($field['container'], 'Computer',
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
                        $ok = $container->updateFieldsValues(['items_id' => $task['computer_id'], 'itemtype' => 'Computer',
                            'plugin_fields_containers_id' => $field['container'], $definition->fields['name'] => $value], 'Computer', false);
                    }
                    $after = self::snapshot($settings, $task['version']);
                    if (!$ok || ($after['fields'][$key]['value'] ?? null) !== $change['value']
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
                    if (!$log || (int) $log['id_search_option'] !== $field['option'] || $log['itemtype'] !== 'Computer'
                        || (int) $log['items_id'] !== $task['computer_id'] || $log['itemtype_link'] !== '' || (int) $log['linked_action'] !== 0
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

    private static function lockRows(array $settings): void
    {
        global $DB;
        $id = (int) $settings['computer_id'];
        $tables = ['glpi_computers'];
        $containers = \Plugin::isPluginActive('fields') ? Snapshot::containers() : [];
        foreach ($containers as $container) { $tables[] = $container['table']; }
        // Fail closed when locking cannot protect the official update-or-add implementation.
        foreach (array_unique($tables) as $table) {
            $status = Outbox::one('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $DB->quote($table));
            if (strtolower((string) ($status['ENGINE'] ?? '')) !== 'innodb') { throw new \RuntimeException('Transactional asset storage is required.'); }
        }
        Outbox::sql('SELECT `id` FROM `glpi_computers` WHERE `id` = ' . $id . ' FOR UPDATE');
        foreach ($containers as $container) {
            if ((int) $container['entities_id'] === (int) $settings['entity_id']) {
                Outbox::sql('SELECT `id` FROM `' . $container['table'] . '` WHERE `items_id` = ' . $id . ' FOR UPDATE');
            }
        }
    }

    private static function configuration(array $settings): void
    {
        $current = Settings::load();
        foreach (['mode', 'registration', 'generation', 'installation', 'connection_id', 'computer_id', 'entity_id',
            'service_user', 'service_profile', 'service_name', 'service_password'] as $key) {
            if (($current[$key] ?? null) !== ($settings[$key] ?? null) || ($current['mode'] ?? '') !== 'agent') {
                throw new \RuntimeException('Agent configuration changed before execution.');
            }
        }
    }

    private static function historyValue(string|int $value, string $type = 'text'): string
    {
        if ($type === 'yesno') { $value = \Dropdown::getYesNo($value); }
        $decoded = json_decode($value);
        return is_array($decoded) || is_object($decoded) ? '' : mb_substr($value, 0, 255);
    }
}
