<?php

declare(strict_types=1);

/** Actual SQLite transactions and constraints. Only MySQL dialect is translated; unknown SQL is executed, never accepted blindly. */
final class AgentSqlite
{
    public PDO $pdo;
    public ?string $failOn = null;
    public ?Closure $beforeQuery = null;
    public string $databaseVersion = '8.0.36';
    private int $affected = 0;

    public function __construct(string $file = ':memory:')
    {
        $this->pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->sqliteCreateFunction('NOW', static fn () => gmdate('Y-m-d H:i:s'), 0);
        $this->pdo->sqliteCreateFunction('UNIX_TIMESTAMP', static fn ($v) => $v === null ? null : strtotime($v . ' UTC'), 1);
        $this->pdo->sqliteCreateFunction('FROM_UNIXTIME', static fn ($v) => gmdate('Y-m-d H:i:s', $v), 1);
        $this->pdo->sqliteCreateFunction('POW', static fn ($a, $b) => $a ** $b, 2);
        $this->pdo->sqliteCreateFunction('LEAST', static fn (...$v) => min($v));
        $this->pdo->sqliteCreateFunction('GREATEST', static fn (...$v) => max($v));
        $this->pdo->sqliteCreateFunction('IF', static fn ($c, $a, $b) => $c ? $a : $b, 3);
        $this->pdo->sqliteCreateFunction('MD5', md5(...), 1);
        $this->pdo->sqliteCreateFunction('CONCAT', static fn (...$values) => implode('', $values));
    }

    public function doQuery(string $sql): PDOStatement|bool
    {
        if ($this->beforeQuery !== null) { ($this->beforeQuery)($sql); }
        if ($this->failOn !== null && str_contains($sql, $this->failOn)) { return false; }
        if ($sql === 'SELECT VERSION() AS version') { return $this->pdo->query('SELECT ' . $this->quote($this->databaseVersion) . ' AS version'); }
        $sql = preg_replace(['/\/\*\+ MAX_EXECUTION_TIME\([1-9][0-9]*\) \*\/ /', '/^SET STATEMENT max_statement_time=0?1?\.[0-9]{3} FOR /'], '', $sql);
        if ($sql === 'SELECT @@SESSION.time_zone AS session_timezone') {
            return $this->pdo->query("SELECT '+00:00' AS session_timezone");
        }
        if ($sql === 'SELECT DATABASE() AS database_name') { return $this->pdo->query("SELECT 'agent_test' AS database_name"); }
        if (preg_match('/^SELECT (GET_LOCK|RELEASE_LOCK)\(/', $sql, $m)) {
            return $this->pdo->query('SELECT 1 AS ' . ($m[1] === 'GET_LOCK' ? 'acquired' : 'released'));
        }
        if ($sql === 'START TRANSACTION') { return $this->pdo->beginTransaction(); }
        // SQLite cannot model InnoDB snapshots; actual lock contention is tested in the isolated lab.
        if ($sql === 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED') { return true; }
        if ($sql === 'COMMIT') { return $this->pdo->commit(); }
        if ($sql === 'ROLLBACK') { return $this->pdo->rollBack(); }
        if (preg_match('/^SHOW INDEX FROM `([^`]+)` WHERE Key_name = \'([^\']+)\' AND Seq_in_index = (\d+)$/', $sql, $match)) {
            $columns = [];
            if ($match[2] === 'PRIMARY') {
                foreach ($this->pdo->query('PRAGMA table_info(`' . $match[1] . '`)') as $column) {
                    if ($column['pk']) { $columns[(int) $column['pk']] = $column['name']; }
                }
            } else {
                foreach ($this->pdo->query('PRAGMA index_list(`' . $match[1] . '`)') as $index) {
                    $info = $this->pdo->query('PRAGMA index_info(`' . $index['name'] . '`)')->fetchAll(PDO::FETCH_ASSOC);
                    if (($info[0]['name'] ?? '') === 'registration_id') {
                        foreach ($info as $column) { $columns[$column['seqno'] + 1] = $column['name']; }
                    }
                }
            }
            return $this->pdo->query('SELECT ' . $this->quote($columns[(int) $match[3]] ?? '') . ' AS Column_name');
        }
        if (preg_match('/^ALTER TABLE `([^`]+)` DROP (PRIMARY KEY|INDEX `agent_computer`), (.*)$/s', $sql, $match)) {
            $table = $match[1];
            $create = $this->pdo->query('SELECT sql FROM sqlite_master WHERE name = ' . $this->quote($table))->fetchColumn();
            if ($match[2] === 'PRIMARY KEY') {
                $create = preg_replace('/,?\s*PRIMARY KEY\s*\([^)]*\)/', '', $create);
                $create = str_replace(' PRIMARY KEY', '', $create);
            } else {
                $create = preg_replace('/,\s*UNIQUE\s*\(`registration_id`,\s*`computer_id`\)/', '', $create);
            }
            $keys = preg_replace('/ADD (PRIMARY KEY|UNIQUE KEY `[^`]+`)/', '$1', $match[3]);
            $keys = preg_replace('/UNIQUE KEY `[^`]+`/', 'UNIQUE', $keys);
            $create = substr(rtrim($create), 0, -1) . ', ' . $keys . ')';
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec('ALTER TABLE `' . $table . '` RENAME TO migration_old');
                $this->pdo->exec($create);
                $this->pdo->exec('INSERT INTO `' . $table . '` SELECT * FROM migration_old');
                $this->pdo->exec('DROP TABLE migration_old');
                return $this->pdo->commit();
            } catch (Throwable $error) { $this->pdo->rollBack(); throw $error; }
        }
        if (str_starts_with($sql, 'CREATE TABLE')) {
            $sql = preg_replace('/\) ENGINE=.*$/s', ')', $sql);
            $sql = preg_replace('/,\s*(?:PRIMARY KEY \(`id`\)|KEY `[^`]+` \([^)]*\))/', '', $sql);
            $sql = preg_replace('/UNIQUE KEY `[^`]+`/', 'UNIQUE', $sql);
            $sql = preg_replace('/`id` INT\s+UNSIGNED NOT NULL AUTO_INCREMENT(?: PRIMARY KEY)?/', '`id` INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
            $sql = str_replace(' UNSIGNED', '', $sql);
            $sql = preg_replace('/,\s*\)$/', ')', $sql);
        }
        $sql = str_replace([' FOR UPDATE', 'INSERT IGNORE'], ['', 'INSERT OR IGNORE'], $sql);
        // SQLite BLOB comparisons model the v4 MySQL byte comparison, not a text collation.
        $sql = str_replace(' AS BINARY)', ' AS BLOB)', $sql);
        if (str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
            $columns = str_contains($sql, '`glpi_plugin_assetsync20_syncqueue`')
                ? '`itemtype`,`items_id`,`glpi_b_connection_id`' : (str_contains($sql, '`glpi_plugin_assetsync20agent_targets`')
                    ? '`itemtype`,`items_id`' : '`itemtype`,`computer_id`');
            $sql = str_replace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT (' . $columns . ') DO UPDATE SET', $sql);
            $sql = preg_replace('/VALUES\(`([^`]+)`\)/', 'excluded.`$1`', $sql);
        }
        $sql = preg_replace('/DATE_ADD\(NOW\(\), INTERVAL (\d+) SECOND\)/', "datetime('now', '+$1 seconds')", $sql);
        $result = $this->pdo->query($sql);
        $this->affected = $result->rowCount();
        return $result;
    }

    public function quote(string $v): string { return $this->pdo->quote($v); }
    public function fetchAssoc(PDOStatement $result): ?array { return $result->fetch(PDO::FETCH_ASSOC) ?: null; }
    public function affectedRows(): int { return $this->affected; }
    public function tableExists(string $table): bool
    {
        return (bool) $this->pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $this->quote($table))->fetchColumn();
    }
    public function fieldExists(string $table, string $column): bool
    {
        return in_array($column, array_column($this->pdo->query('PRAGMA table_info(`' . $table . '`)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
    }
    public function insert(string $table, array $fields): bool
    {
        return (bool) $this->doQuery('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($fields)) . '`) VALUES ('
            . implode(',', array_map($this->value(...), $fields)) . ')');
    }
    public function update(string $table, array $fields, array $where): bool
    {
        $set = [];
        foreach ($fields as $key => $value) { $set[] = '`' . $key . '`=' . $this->value($value); }
        return (bool) $this->doQuery('UPDATE `' . $table . '` SET ' . implode(',', $set) . ' WHERE ' . $this->where($where));
    }
    public function request(array $query): ArrayIterator
    {
        $columns = $query['SELECT'] ?? ['*'];
        $columns = is_array($columns) ? $columns : [$columns];
        $sql = 'SELECT ' . implode(',', array_map(static fn ($v) => is_object($v) ? $v->expression . (!empty($v->alias) ? ' AS ' . $v->alias : '') : $v, $columns))
            . ' FROM `' . $query['FROM'] . '`';
        if (!empty($query['WHERE'])) { $sql .= ' WHERE ' . $this->where($query['WHERE']); }
        if (!empty($query['GROUPBY'])) { $sql .= ' GROUP BY ' . $query['GROUPBY']; }
        if (!empty($query['ORDER'])) {
            $order = $query['ORDER'];
            $sql .= ' ORDER BY ' . (is_object($order) ? str_replace('CAST(glpi_b_connection_id AS BINARY)', 'glpi_b_connection_id COLLATE BINARY', $order->expression) : $order);
        }
        if (!empty($query['LIMIT'])) { $sql .= ' LIMIT ' . (int) $query['LIMIT']; }
        if (!empty($query['START'])) { $sql .= ' OFFSET ' . (int) $query['START']; }
        return new ArrayIterator($this->doQuery($sql)->fetchAll(PDO::FETCH_ASSOC));
    }
    public function firstRow(string $table, array $where): ?array
    {
        return $this->request(['FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1])->current();
    }
    private function where(array $where): string
    {
        $parts = [];
        foreach ($where as $key => $value) {
            if (is_int($key) && is_object($value)) { $parts[] = $value->expression; continue; }
            if ($value === null) { $parts[] = '`' . $key . '` IS NULL'; continue; }
            if (is_array($value)) {
                if (count($value) === 2 && in_array($value[0], ['>', '<', '>=', '<=', '!='], true)) {
                    $parts[] = '`' . $key . '`' . $value[0] . $this->value($value[1]);
                } else {
                    $parts[] = '`' . $key . '` IN (' . implode(',', array_map($this->value(...), $value)) . ')';
                }
            } else { $parts[] = '`' . $key . '`=' . $this->value($value); }
        }
        return implode(' AND ', $parts);
    }
    private function value(mixed $value): string
    {
        if (is_object($value)) { return $value->expression; }
        return $value === null ? 'NULL' : (is_int($value) ? (string) $value : $this->quote((string) $value));
    }
}
