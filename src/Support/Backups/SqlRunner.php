<?php

namespace Nexor\Cms\Support\Backups;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Выполняет SQL-дамп по шагам, запрос за запросом.
 *
 * Курсор — смещение в файле после последнего выполненного запроса. Запросы
 * разделяются «;» вне строк, поэтому точка с запятой внутри текста статьи
 * запрос не режет. Настройки сессии MySQL (кодировка, проверка внешних ключей)
 * живут только до конца соединения — они ставятся заново в начале каждого шага.
 */
class SqlRunner
{
    protected const READ = 4 * 1024 * 1024;

    protected Connection $db;

    /**
     * @param  array{offset?: int, statements?: int}  $state
     */
    public function __construct(protected string $file, protected array $state = [], ?Connection $connection = null)
    {
        $this->db = $connection ?? DB::connection();
        $this->state += ['offset' => 0, 'statements' => 0];
    }

    /**
     * @return array{offset: int, statements: int}
     */
    public function state(): array
    {
        return $this->state;
    }

    public function size(): int
    {
        return is_file($this->file) ? (int) filesize($this->file) : 0;
    }

    public function finished(): bool
    {
        return $this->state['offset'] >= $this->size();
    }

    public function run(?float $deadline): void
    {
        $this->prepareSession();

        $handle = fopen($this->file, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Не найден дамп базы для восстановления.');
        }

        fseek($handle, $this->state['offset']);

        $buffer = '';
        $base = $this->state['offset'];
        $eof = false;

        try {
            while (true) {
                if (! $eof && strlen($buffer) < self::READ) {
                    $read = (string) fread($handle, self::READ);
                    $buffer .= $read;
                    $eof = feof($handle) || $read === '';
                }

                $end = $this->statementEnd($buffer);

                if ($end === null) {
                    if ($eof) {
                        // Хвост без «;» — последний запрос без точки с запятой.
                        $this->execute($buffer);
                        $this->state['offset'] = $base + strlen($buffer);

                        return;
                    }

                    continue;
                }

                $this->execute(substr($buffer, 0, $end));
                $base += $end + 1;
                $buffer = (string) substr($buffer, $end + 1);
                $this->state['offset'] = $base;

                if ($deadline !== null && microtime(true) >= $deadline) {
                    return;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Позиция «;», которая завершает первый запрос в буфере, или null — нужно
     * дочитать. Строки в кавычках и строки-комментарии пропускаются.
     */
    protected function statementEnd(string $buffer): ?int
    {
        $length = strlen($buffer);
        $quote = null;
        $backslash = $this->db->getDriverName() !== 'sqlite';

        for ($i = 0; $i < $length; $i++) {
            $char = $buffer[$i];

            if ($quote !== null) {
                if ($backslash && $char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;

                    continue;
                }

                // Внутри строки интересны только её кавычка и обратный слеш.
                $i += strcspn($buffer, $quote.($backslash ? '\\' : ''), $i + 1);

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;

                continue;
            }

            // Комментарий до конца строки: «-- » или «#» в начале строки.
            if (($char === '-' && ($buffer[$i + 1] ?? '') === '-') || $char === '#') {
                if ($i === 0 || $buffer[$i - 1] === "\n") {
                    $newline = strpos($buffer, "\n", $i);

                    if ($newline === false) {
                        return null;
                    }

                    $i = $newline;

                    continue;
                }
            }

            if ($char === ';') {
                return $i;
            }

            // Перескок к следующему значимому символу.
            $skip = strcspn($buffer, "'\"`;-#\\\n", $i + 1);
            $i += $skip;
        }

        return null;
    }

    protected function execute(string $sql): void
    {
        $sql = trim($this->stripComments($sql));

        // Дамп SQLite обёрнут в транзакцию, а выполняется кусками по шагам —
        // одна транзакция на весь файл тут невозможна.
        if ($sql === '' || preg_match('/^(BEGIN( TRANSACTION)?|COMMIT|END)$/i', $sql)) {
            return;
        }

        try {
            $this->db->unprepared($sql);
        } catch (Throwable $exception) {
            throw new RuntimeException('Ошибка в запросе дампа: '.mb_substr($sql, 0, 200).'… — '.$exception->getMessage(), 0, $exception);
        }

        $this->state['statements']++;
    }

    /**
     * Строки-комментарии перед запросом: `-- users`.
     */
    protected function stripComments(string $sql): string
    {
        return (string) preg_replace('/^\s*(--[^\n]*|#[^\n]*)\n/m', '', ltrim($sql)."\n");
    }

    protected function prepareSession(): void
    {
        if ($this->db->getDriverName() === 'sqlite') {
            $this->db->unprepared('PRAGMA foreign_keys = OFF');

            return;
        }

        $this->db->unprepared('SET NAMES utf8mb4');
        $this->db->unprepared('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->unprepared("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
    }
}
