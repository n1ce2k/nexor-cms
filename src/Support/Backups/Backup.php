<?php

namespace Nexor\Cms\Support\Backups;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nexor\Cms\Support\Nexor;
use RuntimeException;

/**
 * Одна резервная копия: её настройки, ход работы и тома архива.
 *
 * Состояние лежит рядом с архивом в `<id>.json`, а не в базе: копия должна
 * пережить восстановление базы и не попадать в собственный дамп.
 */
class Backup
{
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** Что из файлов попадает в копию. */
    public const FILES_ALL = 'all';

    public const FILES_UPLOADS = 'uploads';

    public const FILES_NONE = 'none';

    /** Копия, которая не двигалась столько секунд, считается брошенной. */
    public const STALLED_AFTER = 600;

    /**
     * @param  array<string, mixed>  $data
     */
    final public function __construct(public array $data) {}

    public static function directory(): string
    {
        $path = rtrim((string) (config('nexor.backups.path') ?: storage_path('app'.DIRECTORY_SEPARATOR.'backups')), '\\/');

        File::ensureDirectoryExists($path);

        // Папка вне public, но на случай корня сайта в папке проекта закрываем и её.
        if (! is_file($path.DIRECTORY_SEPARATOR.'.htaccess')) {
            @file_put_contents($path.DIRECTORY_SEPARATOR.'.htaccess', implode("\n", [
                '<IfModule mod_authz_core.c>',
                '    Require all denied',
                '</IfModule>',
                '<IfModule !mod_authz_core.c>',
                '    Order allow,deny',
                '    Deny from all',
                '</IfModule>',
                '',
            ]));
        }

        return $path;
    }

    /**
     * Папка, от которой считаются пути в архиве, — корень проекта.
     */
    public static function root(): string
    {
        return rtrim((string) (config('nexor.backups.root') ?: base_path()), '\\/');
    }

    /**
     * @param  array{database?: bool, files?: string, exclude_vendor?: bool, part_mb?: int|null}  $options
     */
    public static function create(array $options, ?string $author = null): static
    {
        $options = [
            'database' => (bool) ($options['database'] ?? true),
            'files' => in_array($options['files'] ?? null, [self::FILES_ALL, self::FILES_UPLOADS, self::FILES_NONE], true)
                ? $options['files']
                : self::FILES_ALL,
            'exclude_vendor' => (bool) ($options['exclude_vendor'] ?? true),
            'part_mb' => ($options['part_mb'] ?? null) ? (int) $options['part_mb'] : null,
        ];

        if (! $options['database'] && $options['files'] === self::FILES_NONE) {
            throw new RuntimeException('Нечего копировать: выберите базу, файлы или и то, и другое.');
        }

        $backup = new static([
            'id' => 'backup_'.now()->format('Y-m-d_His').'_'.Str::lower(Str::random(4)),
            'status' => self::RUNNING,
            'phase' => $options['files'] === self::FILES_NONE ? 'database' : 'scan',
            'options' => $options,
            'author' => $author,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'finished_at' => null,
            'error' => null,
            'progress' => [
                'files_total' => 0,
                'files_done' => 0,
                'bytes_total' => 0,
                'bytes_done' => 0,
                'tables_total' => 0,
                'tables_done' => 0,
                'skipped' => 0,
            ],
            'cursor' => [],
            'archive' => [],
            'dump' => [],
            'cms_version' => Nexor::VERSION,
        ]);

        $backup->save();

        return $backup;
    }

    /**
     * @return array<int, static>
     */
    public static function all(): array
    {
        $backups = [];

        foreach (glob(self::directory().DIRECTORY_SEPARATOR.'backup_*.json') ?: [] as $file) {
            if ($backup = static::find(basename($file, '.json'))) {
                $backups[] = $backup;
            }
        }

        usort($backups, fn (self $a, self $b): int => strcmp((string) $b->data['created_at'], (string) $a->data['created_at']));

        return $backups;
    }

    public static function find(string $id): ?static
    {
        if (! self::validId($id)) {
            return null;
        }

        $file = self::directory().DIRECTORY_SEPARATOR.$id.'.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? new static($data) : null;
    }

    /**
     * Идёт ли сейчас другая копия — две разом только мешали бы друг другу.
     */
    public static function running(): ?static
    {
        foreach (self::all() as $backup) {
            if ($backup->isRunning() && ! $backup->isStalled()) {
                return $backup;
            }
        }

        return null;
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}_[a-z0-9]{4}$/', $id) === 1;
    }

    public function id(): string
    {
        return $this->data['id'];
    }

    public function isRunning(): bool
    {
        return $this->data['status'] === self::RUNNING;
    }

    public function isStalled(): bool
    {
        return $this->isRunning()
            && now()->diffInSeconds($this->data['updated_at'], true) > self::STALLED_AFTER;
    }

    /**
     * Путь к служебному файлу копии: `list`, `sql`, `json`.
     */
    public function path(string $extension): string
    {
        return self::directory().DIRECTORY_SEPARATOR.$this->id().'.'.$extension;
    }

    /**
     * Готовые тома архива.
     *
     * @return array<int, array{name: string, size: int}>
     */
    public function parts(): array
    {
        return array_values($this->data['archive']['parts'] ?? []);
    }

    public function partPath(int $index): ?string
    {
        $part = $this->parts()[$index] ?? null;

        return $part ? self::directory().DIRECTORY_SEPARATOR.$part['name'] : null;
    }

    public function size(): int
    {
        return (int) array_sum(array_column($this->parts(), 'size'));
    }

    public function save(): void
    {
        $this->data['updated_at'] = now()->toIso8601String();

        file_put_contents($this->path('json'), json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function fail(string $message): void
    {
        $this->data['status'] = self::FAILED;
        $this->data['error'] = $message;
        $this->data['finished_at'] = now()->toIso8601String();
        $this->cleanup();
        $this->save();
    }

    /**
     * Убирает служебные файлы работы: список файлов, дамп, манифест.
     */
    public function cleanup(): void
    {
        foreach (['list', 'sql', 'manifest.json', 'readme.txt'] as $extension) {
            @unlink($this->path($extension));
        }
    }

    /**
     * Удаляет копию целиком: тома, состояние, служебные файлы.
     */
    public function delete(): void
    {
        $this->cleanup();

        foreach (glob(self::directory().DIRECTORY_SEPARATOR.$this->id().'.tar.gz*') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($this->path('json'));
    }
}
