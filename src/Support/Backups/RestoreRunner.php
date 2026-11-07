<?php

namespace Nexor\Cms\Support\Backups;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Восстанавливает сайт из копии по шагам.
 *
 * Этапы: распаковка архива поверх сайта (extract) → выполнение дампа базы
 * (database) → сброс кешей (finish). Файлы, которых в копии нет, не удаляются:
 * восстановление возвращает то, что было в копии, а не стирает лишнее.
 *
 * Всегда пропускаются: папка копий, `.git`, `bootstrap/cache` (кеш конфига
 * старого сайта подменил бы настройки нового). По выбору — `.env`, `vendor`
 * и `node_modules`.
 */
class RestoreRunner
{
    public function __construct(protected Restore $restore) {}

    public function step(?float $seconds): Restore
    {
        if (! $this->restore->isRunning()) {
            return $this->restore;
        }

        $deadline = $seconds === null ? null : microtime(true) + $seconds;

        try {
            do {
                match ($this->restore->data['phase']) {
                    'extract' => $this->extract($deadline),
                    'database' => $this->database($deadline),
                    'finish' => $this->finish(),
                    default => throw new RuntimeException('Неизвестный этап восстановления: '.$this->restore->data['phase']),
                };

                if ($this->restore->isRunning()) {
                    $this->restore->save();
                }
            } while ($this->restore->isRunning() && ($deadline === null || microtime(true) < $deadline));
        } catch (Throwable $exception) {
            report($exception);
            $this->restore->fail($exception->getMessage());
        }

        return $this->restore;
    }

    protected function extract(?float $deadline): void
    {
        $backup = Backup::find($this->restore->data['backup']);

        if ($backup === null || $backup->parts() === []) {
            throw new RuntimeException('Копия '.$this->restore->data['backup'].' не найдена — её удалили?');
        }

        $reader = new VolumeReader(array_map(
            fn (array $part): string => Backup::directory().DIRECTORY_SEPARATOR.$part['name'],
            $backup->parts(),
        ));

        $extractor = new ArchiveExtractor($reader, $this->target(...), $this->restore->data['archive']);
        $extractor->run($deadline);

        $state = $extractor->state();
        $this->restore->data['archive'] = $state;
        $this->restore->data['progress']['compressed_done'] = $state['offset'];
        $this->restore->data['progress']['compressed_total'] = $reader->size();
        $this->restore->data['progress']['files_written'] = $state['written'];
        $this->restore->data['progress']['files_skipped'] = $state['skipped'];

        if (! $extractor->finished()) {
            return;
        }

        $hasDump = is_file($this->restore->sqlPath());

        if ($this->restore->data['options']['database'] && ! $hasDump) {
            $this->restore->data['notes'][] = 'В копии нет дампа базы — база не менялась.';
        }

        $this->restore->data['phase'] = $this->restore->data['options']['database'] && $hasDump ? 'database' : 'finish';
    }

    protected function database(?float $deadline): void
    {
        $runner = new SqlRunner($this->restore->sqlPath(), $this->restore->data['sql']);
        $runner->run($deadline);

        $this->restore->data['sql'] = $runner->state();
        $this->restore->data['progress']['sql_done'] = $runner->state()['offset'];
        $this->restore->data['progress']['sql_total'] = $runner->size();
        $this->restore->data['progress']['statements'] = $runner->state()['statements'];

        if ($runner->finished()) {
            $this->restore->data['phase'] = 'finish';
        }
    }

    /**
     * Кеши старого сайта — настройки, меню, шаблоны — больше не годятся.
     */
    protected function finish(): void
    {
        try {
            Cache::flush();
        } catch (Throwable) {
            // Кеш в базе мог исчезнуть вместе со старыми таблицами — не страшно.
        }

        // Без clear-compiled из optimize:clear: список пакетов Laravel пересоберёт
        // сам, а удалять его посреди работы сайта незачем.
        foreach (['config:clear', 'route:clear', 'view:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (Throwable $exception) {
                $this->restore->data['notes'][] = 'Не выполнено '.$command.': '.$exception->getMessage();
            }
        }

        $this->restore->finish(Restore::DONE);
    }

    /**
     * Куда положить запись архива; null — пропустить.
     */
    protected function target(string $path, string $kind): ?string
    {
        $path = $this->normalise($path);

        if ($path === null) {
            $note = 'В архиве были записи с небезопасным путём (с «..» или от корня диска) — пропущены.';

            if (! in_array($note, $this->restore->data['notes'], true)) {
                $this->restore->data['notes'][] = $note;
            }

            return null;
        }

        $options = $this->restore->data['options'];

        if (str_starts_with($path.'/', BackupRunner::META.'/')) {
            return $path === BackupRunner::META.'/database.sql' && $kind === 'file' && $options['database']
                ? $this->restore->sqlPath()
                : null;
        }

        if (! $options['files'] || $this->skips($path, $options)) {
            return null;
        }

        return Backup::root().'/'.$path;
    }

    /**
     * @param  array<string, bool>  $options
     */
    protected function skips(string $path, array $options): bool
    {
        $prefixes = ['.git', 'bootstrap/cache', 'public/hot', $this->backupsFolder()];

        if (! $options['vendor']) {
            $prefixes = [...$prefixes, 'vendor', 'node_modules'];
        }

        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
                // Сама папка bootstrap/cache нужна Laravel — её оставляем.
                return ! ($path === 'bootstrap/cache' && $prefix === 'bootstrap/cache');
            }
        }

        return $path === '.env' && ! $options['env'];
    }

    /**
     * Путь из архива без `..`, корня диска и обратных слешей; null — путь опасный.
     */
    protected function normalise(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) || str_contains($path, "\0")) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return null;
            }

            if ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /**
     * Папка копий относительно корня сайта — её восстановление не трогает.
     */
    protected function backupsFolder(): string
    {
        $root = str_replace('\\', '/', realpath(Backup::root()) ?: Backup::root());
        $directory = str_replace('\\', '/', realpath(Backup::directory()) ?: Backup::directory());

        return str_starts_with(strtolower($directory), strtolower($root).'/')
            ? substr($directory, strlen($root) + 1)
            : '';
    }
}
