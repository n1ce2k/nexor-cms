<?php

namespace Nexor\Cms\Support\Backups;

use Illuminate\Support\Str;

/**
 * Восстановление сайта из копии: что восстанавливаем и докуда дошли.
 *
 * Состояние — файл `restore.json` в папке копий, а не строка в базе: база по
 * ходу дела заменяется целиком. По той же причине шаги восстановления идут не
 * по сессии (таблица сессий и пользователи тоже заменяются), а по одноразовому
 * токену, который выдаётся при запуске.
 */
class Restore
{
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /**
     * @param  array<string, mixed>  $data
     */
    final public function __construct(public array $data) {}

    public static function file(): string
    {
        return Backup::directory().DIRECTORY_SEPARATOR.'restore.json';
    }

    public static function current(): ?static
    {
        $data = is_file(self::file()) ? json_decode((string) file_get_contents(self::file()), true) : null;

        return is_array($data) ? new static($data) : null;
    }

    /**
     * @param  array{files?: bool, database?: bool, env?: bool, vendor?: bool}  $options
     */
    public static function start(Backup $backup, array $options, ?string $author = null): static
    {
        $restore = new static([
            'status' => self::RUNNING,
            'phase' => 'extract',
            'backup' => $backup->id(),
            'backup_created_at' => $backup->data['created_at'],
            'options' => [
                'files' => (bool) ($options['files'] ?? true),
                'database' => (bool) ($options['database'] ?? true),
                'env' => (bool) ($options['env'] ?? false),
                'vendor' => (bool) ($options['vendor'] ?? false),
            ],
            'author' => $author,
            'token_hash' => null,
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'finished_at' => null,
            'error' => null,
            'notes' => [],
            'progress' => [
                'compressed_done' => 0,
                'compressed_total' => $backup->size(),
                'files_written' => 0,
                'files_skipped' => 0,
                'sql_done' => 0,
                'sql_total' => 0,
                'statements' => 0,
            ],
            'archive' => [],
            'sql' => [],
        ]);

        $restore->cleanup();
        $restore->save();

        return $restore;
    }

    /**
     * Почему PHP не сможет писать в папку сайта; null — сможет.
     *
     * Проверяется пробным файлом, а не правами: в Docker папка кода бывает
     * подключена только на чтение при 777 на файлах — права это не покажут.
     */
    public static function rootProblem(): ?string
    {
        $probe = Backup::root().DIRECTORY_SEPARATOR.'.nexor-write-test-'.Str::random(8);

        if (@file_put_contents($probe, 'ok') === false) {
            return 'PHP сайта не может писать в папку '.Backup::root().ArchiveExtractor::reason()
                .' Файлы восстановить не получится: восстановите только базу, а файлы — из консоли'
                .' или распакуйте архив вручную.';
        }

        @unlink($probe);

        return null;
    }

    /**
     * Новый токен шагов. Прежний перестаёт действовать.
     */
    public function issueToken(): string
    {
        $token = Str::random(48);

        $this->data['token_hash'] = hash('sha256', $token);
        $this->save();

        return $token;
    }

    public function checkToken(?string $token): bool
    {
        return $this->isRunning()
            && is_string($token)
            && $this->data['token_hash'] !== null
            && hash_equals($this->data['token_hash'], hash('sha256', $token));
    }

    public function isRunning(): bool
    {
        return $this->data['status'] === self::RUNNING;
    }

    public function isStalled(): bool
    {
        return $this->isRunning()
            && now()->diffInSeconds($this->data['updated_at'], true) > Backup::STALLED_AFTER;
    }

    /**
     * Дамп базы, вынутый из архива.
     */
    public function sqlPath(): string
    {
        return Backup::directory().DIRECTORY_SEPARATOR.'restore.sql';
    }

    public function lockPath(): string
    {
        return Backup::directory().DIRECTORY_SEPARATOR.'restore.lock';
    }

    public function save(): void
    {
        $this->data['updated_at'] = now()->toIso8601String();

        file_put_contents(self::file(), json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function fail(string $message): void
    {
        $this->finish(self::FAILED);
        $this->data['error'] = $message;
        $this->save();
    }

    public function finish(string $status): void
    {
        $this->data['status'] = $status;
        $this->data['phase'] = $status === self::DONE ? 'done' : $this->data['phase'];
        $this->data['finished_at'] = now()->toIso8601String();
        $this->data['token_hash'] = null;
        $this->cleanup();
        $this->save();
    }

    public function cleanup(): void
    {
        @unlink($this->sqlPath());
    }
}
