<?php

namespace Nexor\Cms\Support\Backups;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Дамп базы в SQL — своими силами, без mysqldump: на хостинге его часто нет.
 *
 * Пишется по шагам: курсор — номер таблицы и последний выгруженный ключ, так
 * что большая таблица выгружается за несколько запросов. Строки идут в порядке
 * первичного ключа, а следующая порция берётся «после последнего ключа», а не
 * по смещению — иначе на больших таблицах каждая порция читала бы всё заново.
 *
 * Поддерживаются MySQL/MariaDB (основная база сайтов) и SQLite (тесты и
 * маленькие установки).
 */
class DatabaseDumper
{
    /** Строк в одном SELECT. */
    protected const CHUNK = 500;

    /** Строк в одном INSERT. */
    protected const ROWS_PER_INSERT = 100;

    protected Connection $db;

    /**
     * @param  array{tables?: array<int, string>, table?: int, after?: mixed, offset?: int, started?: bool, rows?: int}  $state
     */
    public function __construct(protected string $file, protected array $state = [], ?Connection $connection = null)
    {
        $this->db = $connection ?? DB::connection();

        if (! in_array($this->driver(), ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException('Дамп базы умеет MySQL, MariaDB и SQLite, а сайт работает на '.$this->driver().'.');
        }

        $this->state += ['table' => 0, 'after' => null, 'offset' => 0, 'started' => false, 'rows' => 0];
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    public function tablesTotal(): int
    {
        return count($this->tables());
    }

    public function tablesDone(): int
    {
        return min($this->state['table'], $this->tablesTotal());
    }

    public function finished(): bool
    {
        return $this->state['table'] >= $this->tablesTotal() && ($this->state['closed'] ?? false);
    }

    /**
     * Выгружает, пока не выйдет время. `$deadline` — microtime, null — до конца.
     */
    public function run(?float $deadline): void
    {
        if (! $this->state['started']) {
            $this->append($this->opening());
            $this->state['started'] = true;
        }

        $tables = $this->tables();

        while ($this->state['table'] < count($tables)) {
            $table = $tables[$this->state['table']];

            if ($this->state['after'] === null && $this->state['offset'] === 0 && ! ($this->state['created'] ?? false)) {
                $this->append($this->createStatement($table));
                $this->state['created'] = true;
            }

            $done = $this->skipsData($table) || $this->dumpChunk($table);

            if ($done) {
                $this->append($this->afterTable($table));
                $this->state['table']++;
                $this->state['after'] = null;
                $this->state['offset'] = 0;
                $this->state['created'] = false;
            }

            // Время проверяется после порции: хотя бы одна порция за шаг будет.
            if ($deadline !== null && microtime(true) >= $deadline) {
                return;
            }
        }

        if (! ($this->state['closed'] ?? false)) {
            $this->append($this->closing());
            $this->state['closed'] = true;
        }
    }

    /**
     * Таблицы базы — список фиксируется на первом шаге, чтобы курсор не съехал.
     *
     * @return array<int, string>
     */
    protected function tables(): array
    {
        if (! isset($this->state['tables'])) {
            $rows = $this->isSqlite()
                ? $this->db->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name")
                : $this->db->select("show full tables where Table_type = 'BASE TABLE'");

            $this->state['tables'] = array_values(array_map(
                fn (object $row): string => (string) array_values((array) $row)[0],
                $rows,
            ));
        }

        return $this->state['tables'];
    }

    /**
     * Одна порция строк. true — таблица выгружена.
     */
    protected function dumpChunk(string $table): bool
    {
        $keys = $this->primaryKey($table);
        $quoted = $this->quoteIdentifier($table);

        if (count($keys) === 1) {
            $key = $this->quoteIdentifier($keys[0]);
            $rows = $this->state['after'] === null
                ? $this->db->select("select * from {$quoted} order by {$key} limit ".self::CHUNK)
                : $this->db->select("select * from {$quoted} where {$key} > ? order by {$key} limit ".self::CHUNK, [$this->state['after']]);
        } else {
            $order = $keys === [] ? '' : ' order by '.implode(', ', array_map($this->quoteIdentifier(...), $keys));
            $rows = $this->db->select("select * from {$quoted}{$order} limit ".self::CHUNK.' offset '.(int) $this->state['offset']);
        }

        if ($rows === []) {
            return true;
        }

        foreach (array_chunk($rows, self::ROWS_PER_INSERT) as $batch) {
            $this->append($this->insertStatement($table, $batch));
        }

        $this->state['rows'] += count($rows);

        if (count($keys) === 1) {
            $this->state['after'] = ((array) end($rows))[$keys[0]];
        } else {
            $this->state['offset'] += count($rows);
        }

        return count($rows) < self::CHUNK;
    }

    /**
     * @return array<int, string>
     */
    protected function primaryKey(string $table): array
    {
        if ($this->isSqlite()) {
            $columns = array_filter(
                $this->db->select('pragma table_info('.$this->quoteIdentifier($table).')'),
                fn (object $column): bool => (int) $column->pk > 0,
            );
            usort($columns, fn (object $a, object $b): int => $a->pk <=> $b->pk);

            return array_map(fn (object $column): string => $column->name, $columns);
        }

        $rows = $this->db->select('show keys from '.$this->quoteIdentifier($table)." where Key_name = 'PRIMARY'");
        usort($rows, fn (object $a, object $b): int => $a->Seq_in_index <=> $b->Seq_in_index);

        return array_map(fn (object $row): string => $row->Column_name, $rows);
    }

    /**
     * Сессии и кеш нужны только живому сайту: в копию идёт их структура.
     */
    protected function skipsData(string $table): bool
    {
        $prefix = $this->db->getTablePrefix();

        foreach ((array) config('nexor.backups.skip_data', []) as $name) {
            if ($table === $prefix.$name) {
                return true;
            }
        }

        return false;
    }

    protected function createStatement(string $table): string
    {
        $quoted = $this->quoteIdentifier($table);

        if ($this->isSqlite()) {
            $sql = $this->db->selectOne("select sql from sqlite_master where type = 'table' and name = ?", [$table])->sql;

            return "\n-- {$table}\nDROP TABLE IF EXISTS {$quoted};\n{$sql};\n";
        }

        $create = array_values((array) $this->db->selectOne("show create table {$quoted}"))[1];

        return "\n-- {$table}\nDROP TABLE IF EXISTS {$quoted};\n{$create};\n";
    }

    /**
     * Индексы SQLite живут отдельно от таблицы — создаём их после данных.
     */
    protected function afterTable(string $table): string
    {
        if (! $this->isSqlite()) {
            return '';
        }

        $indexes = $this->db->select("select sql from sqlite_master where type = 'index' and tbl_name = ? and sql is not null", [$table]);

        return implode('', array_map(fn (object $index): string => $index->sql.";\n", $indexes));
    }

    /**
     * @param  array<int, object>  $rows
     */
    protected function insertStatement(string $table, array $rows): string
    {
        $columns = array_keys((array) $rows[0]);
        $values = array_map(
            fn (object $row): string => '('.implode(', ', array_map($this->literal(...), array_values((array) $row))).')',
            $rows,
        );

        return 'INSERT INTO '.$this->quoteIdentifier($table)
            .' ('.implode(', ', array_map($this->quoteIdentifier(...), $columns)).') VALUES'
            ."\n".implode(",\n", $values).";\n";
    }

    protected function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            // Двоичные данные не переживут текстовый файл в UTF-8 — пишем в hex.
            ! mb_check_encoding((string) $value, 'UTF-8') => $this->isSqlite()
                ? "X'".bin2hex((string) $value)."'"
                : '0x'.bin2hex((string) $value),
            default => $this->db->getPdo()->quote((string) $value),
        };
    }

    protected function opening(): string
    {
        $head = '-- NEXOR CMS: дамп базы '.$this->db->getDatabaseName().', '.date('Y-m-d H:i:s')."\n";

        return $this->isSqlite()
            ? $head."PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n"
            : $head."SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    }

    protected function closing(): string
    {
        return $this->isSqlite() ? "\nCOMMIT;\n" : "\nSET FOREIGN_KEY_CHECKS=1;\n";
    }

    protected function quoteIdentifier(string $name): string
    {
        return $this->isSqlite()
            ? '"'.str_replace('"', '""', $name).'"'
            : '`'.str_replace('`', '``', $name).'`';
    }

    protected function append(string $sql): void
    {
        if ($sql !== '' && file_put_contents($this->file, $sql, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать дамп базы — проверьте место на диске.');
        }
    }

    protected function isSqlite(): bool
    {
        return $this->driver() === 'sqlite';
    }

    protected function driver(): string
    {
        return $this->db->getDriverName();
    }
}
