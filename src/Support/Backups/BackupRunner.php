<?php

namespace Nexor\Cms\Support\Backups;

use Illuminate\Support\Facades\DB;
use Nexor\Cms\Support\Nexor;
use RuntimeException;
use Throwable;

/**
 * Делает резервную копию по шагам.
 *
 * Шаг работает, пока не выйдет отведённое время, и сохраняет курсор — панель
 * зовёт следующий шаг, пока копия не будет готова. Так копия собирается на
 * любом хостинге, сколько бы секунд он ни давал одному запросу. Консольная
 * команда идёт тем же путём, только без ограничения по времени.
 *
 * Этапы: обход файлов (scan) → дамп базы (database) → служебные файлы
 * (prepare) → запись архива (archive) → готово.
 */
class BackupRunner
{
    /** Сколько байт файла читается за раз. */
    protected const READ = 1024 * 1024;

    /** Папка служебных файлов внутри архива. */
    public const META = '_backup';

    /** Папка с копиями — обход её пропускает, чтобы копия не копировала копии. */
    protected ?string $backupsPath = null;

    public function __construct(protected Backup $backup) {}

    /**
     * Один шаг. `$seconds` — сколько ему дано; null — до конца.
     */
    public function step(?float $seconds): Backup
    {
        if (! $this->backup->isRunning()) {
            return $this->backup;
        }

        $deadline = $seconds === null ? null : microtime(true) + $seconds;

        try {
            // Хотя бы один проход: иначе при совсем коротком шаге копия не двигалась бы.
            do {
                match ($this->backup->data['phase']) {
                    'scan' => $this->scan(),
                    'database' => $this->database($deadline),
                    'prepare' => $this->prepare(),
                    'archive' => $this->archive($deadline),
                    default => throw new RuntimeException('Неизвестный этап копии: '.$this->backup->data['phase']),
                };

                $this->backup->save();
            } while ($this->backup->isRunning() && ($deadline === null || microtime(true) < $deadline));
        } catch (Throwable $exception) {
            report($exception);
            $this->backup->fail($exception->getMessage());
        }

        return $this->backup;
    }

    /**
     * Обходит файлы и пишет их список. Обход быстрый — делается за один шаг,
     * а читать и сжимать сами файлы будет архив.
     */
    protected function scan(): void
    {
        // Настоящий путь: на хостинге папка сайта часто сама ссылка, и без этого
        // каждая папка внутри выглядела бы ссылкой и пропускалась.
        $root = rtrim(str_replace('\\', '/', realpath(Backup::root()) ?: Backup::root()), '/');
        $list = fopen($this->backup->path('list'), 'wb');

        if ($list === false) {
            throw new RuntimeException('Не удалось создать список файлов в '.Backup::directory().'.');
        }

        $files = 0;
        $bytes = 0;
        $stack = $this->backup->data['options']['files'] === Backup::FILES_UPLOADS ? ['storage/app'] : [''];

        while ($stack !== []) {
            $relative = array_pop($stack);
            $absolute = $relative === '' ? $root : $root.'/'.$relative;

            if (! is_dir($absolute) || $this->isLink($absolute)) {
                continue;
            }

            if ($relative !== '') {
                fwrite($list, "d\t{$relative}\n");
            }

            // Кеш, сессии и логи — только сами папки: без них Laravel не стартует.
            if ($this->keepsOnlyFolder($relative)) {
                continue;
            }

            foreach (scandir($absolute) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $child = $relative === '' ? $entry : $relative.'/'.$entry;
                $path = $absolute.'/'.$entry;

                if ($this->skips($child, $path)) {
                    continue;
                }

                if (is_dir($path)) {
                    $stack[] = $child;
                } elseif (is_file($path) && ! is_link($path)) {
                    fwrite($list, "f\t{$child}\n");
                    $files++;
                    $bytes += (int) @filesize($path);
                }
            }
        }

        fclose($list);

        $this->backup->data['progress']['files_total'] = $files;
        $this->backup->data['progress']['bytes_total'] = $bytes;
        $this->backup->data['phase'] = $this->backup->data['options']['database'] ? 'database' : 'prepare';
    }

    protected function database(?float $deadline): void
    {
        $dumper = new DatabaseDumper($this->backup->path('sql'), $this->backup->data['dump']);
        $dumper->run($deadline);

        $this->backup->data['dump'] = $dumper->state();
        $this->backup->data['progress']['tables_total'] = $dumper->tablesTotal();
        $this->backup->data['progress']['tables_done'] = $dumper->tablesDone();

        if ($dumper->finished()) {
            $this->backup->data['phase'] = 'prepare';
        }
    }

    /**
     * Дописывает в конец списка служебные файлы: дамп, манифест, инструкцию.
     */
    protected function prepare(): void
    {
        $manifest = [
            'cms' => 'NEXOR CMS',
            'cms_version' => Nexor::VERSION,
            'created_at' => $this->backup->data['created_at'],
            'site' => config('app.url'),
            'options' => $this->backup->data['options'],
            'database' => $this->backup->data['options']['database'] ? [
                'driver' => DB::connection()->getDriverName(),
                'name' => DB::connection()->getDatabaseName(),
                'tables' => $this->backup->data['progress']['tables_total'],
                'file' => self::META.'/database.sql',
            ] : null,
        ];

        file_put_contents($this->backup->path('manifest.json'), json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($this->backup->path('readme.txt'), $this->readme());

        $extras = ["d\t".self::META];

        if ($this->backup->data['options']['database']) {
            $extras[] = "f\t".self::META."/database.sql\t".$this->backup->path('sql');
        }

        $extras[] = "f\t".self::META."/manifest.json\t".$this->backup->path('manifest.json');
        $extras[] = "f\t".self::META."/README.txt\t".$this->backup->path('readme.txt');

        file_put_contents($this->backup->path('list'), implode("\n", $extras)."\n", FILE_APPEND);

        $this->backup->data['phase'] = 'archive';
    }

    /**
     * Пишет файлы из списка в архив, пока не выйдет время.
     */
    protected function archive(?float $deadline): void
    {
        $options = $this->backup->data['options'];
        $writer = new ArchiveWriter(
            Backup::directory(),
            $this->backup->id(),
            $options['part_mb'] ? $options['part_mb'] * 1024 * 1024 : $this->partBytesOverride(),
            $this->backup->data['archive'],
        );

        $cursor = $this->backup->data['cursor'] + ['offset' => 0, 'entry' => null, 'written' => 0, 'size' => 0];
        $list = fopen($this->backup->path('list'), 'rb');
        fseek($list, $cursor['offset']);

        try {
            do {
                if ($cursor['entry'] === null) {
                    $line = fgets($list);

                    if ($line === false) {
                        $writer->finish();
                        $this->finish($writer);

                        return;
                    }

                    $cursor['offset'] = ftell($list);
                    $cursor['entry'] = rtrim($line, "\r\n");
                    $cursor['written'] = 0;
                    $cursor['size'] = null;
                }

                if ($this->writeEntry($writer, $cursor, $deadline)) {
                    $cursor['entry'] = null;
                }
            } while ($deadline === null || microtime(true) < $deadline);
        } finally {
            fclose($list);
            $writer->flush();
            $this->backup->data['archive'] = $writer->state();
            $this->backup->data['cursor'] = $cursor;
        }
    }

    /**
     * Пишет запись списка. true — запись дописана целиком.
     *
     * @param  array{entry: string, written: int, size: int|null}  $cursor
     */
    protected function writeEntry(ArchiveWriter $writer, array &$cursor, ?float $deadline): bool
    {
        $parts = explode("\t", $cursor['entry']);
        [$type, $name] = [$parts[0], $parts[1] ?? ''];
        $source = $parts[2] ?? Backup::root().'/'.$name;

        if ($type === 'd') {
            $writer->addDirectory($name, (int) @filemtime($source) ?: time());

            return true;
        }

        // Файл исчез или не читается — пропускаем, а не роняем всю копию.
        if ($cursor['size'] === null) {
            if (! is_file($source) || ! is_readable($source)) {
                $this->backup->data['progress']['skipped']++;

                return true;
            }

            $cursor['size'] = (int) filesize($source);
            $writer->beginFile($name, $cursor['size'], (int) filemtime($source));
        }

        $handle = @fopen($source, 'rb');

        if ($handle !== false) {
            fseek($handle, $cursor['written']);

            while ($cursor['written'] < $cursor['size']) {
                $chunk = fread($handle, min(self::READ, $cursor['size'] - $cursor['written']));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $writer->write($chunk);
                $cursor['written'] += strlen($chunk);
                $this->backup->data['progress']['bytes_done'] += strlen($chunk);

                if ($cursor['written'] < $cursor['size'] && $deadline !== null && microtime(true) >= $deadline) {
                    fclose($handle);

                    return false;
                }
            }

            fclose($handle);
        }

        // Файл стал короче, пока его читали: размер в заголовке уже записан,
        // добиваем нулями, иначе архив разойдётся.
        if ($cursor['written'] < $cursor['size']) {
            $writer->write(str_repeat("\0", $cursor['size'] - $cursor['written']));
        }

        $writer->pad($cursor['size']);

        if (! str_starts_with($name, self::META.'/')) {
            $this->backup->data['progress']['files_done']++;
        }

        return true;
    }

    protected function finish(ArchiveWriter $writer): void
    {
        $this->backup->data['archive'] = $writer->state();
        $this->backup->data['status'] = Backup::DONE;
        $this->backup->data['phase'] = 'done';
        $this->backup->data['finished_at'] = now()->toIso8601String();
        $this->backup->cleanup();
    }

    /**
     * Не идти внутрь: зависимости (если просили без них), git, сами копии.
     */
    protected function skips(string $relative, string $absolute): bool
    {
        $skip = ['.git', 'public/hot'];

        if ($this->backup->data['options']['exclude_vendor']) {
            $skip = [...$skip, 'vendor', 'node_modules'];
        }

        if (in_array($relative, $skip, true)) {
            return true;
        }

        $this->backupsPath ??= $this->normalise((string) (realpath(Backup::directory()) ?: Backup::directory()));

        return $this->normalise($absolute) === $this->backupsPath;
    }

    protected function keepsOnlyFolder(string $relative): bool
    {
        return in_array($relative, [
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/framework/testing',
            'storage/logs',
        ], true);
    }

    /**
     * Ссылка или junction (`public/storage` на Windows) — внутрь не идём:
     * её содержимое и так лежит в копии под настоящим путём.
     */
    protected function isLink(string $path): bool
    {
        if (is_link($path)) {
            return true;
        }

        $real = realpath($path);

        return $real !== false && ! $this->samePath($real, $path);
    }

    protected function samePath(string $one, string $other): bool
    {
        return $this->normalise($one) === $this->normalise($other);
    }

    /**
     * Путь для сравнения: прямые слеши, на Windows — без учёта регистра.
     */
    protected function normalise(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }

    /**
     * Размер тома в байтах для тестов: мегабайт на тест — слишком много.
     */
    protected function partBytesOverride(): ?int
    {
        $bytes = config('nexor.backups.part_bytes');

        return $bytes ? (int) $bytes : null;
    }

    protected function readme(): string
    {
        $name = $this->backup->id();
        $split = (bool) $this->backup->data['options']['part_mb'];

        $lines = [
            'Резервная копия NEXOR CMS '.Nexor::VERSION,
            'Создана: '.$this->backup->data['created_at'],
            'Сайт: '.config('app.url'),
            '',
            'Восстановление',
            '',
        ];

        if ($split) {
            $lines[] = '1. Склейте тома в один архив (по порядку номеров):';
            $lines[] = "   Linux / macOS:  cat {$name}.tar.gz.* > {$name}.tar.gz";
            $lines[] = "   Windows:        copy /b {$name}.tar.gz.001+{$name}.tar.gz.002 {$name}.tar.gz  (перечислите все тома)";
            $lines[] = "   Или откройте {$name}.tar.gz.001 в 7-Zip — он соберёт тома сам.";
        } else {
            $lines[] = '1. Архив один — склеивать ничего не нужно.';
        }

        return implode("\n", [
            ...$lines,
            "2. Распакуйте архив в папку сайта:  tar -xzf {$name}.tar.gz",
            '3. База (если она в копии) — файл '.self::META.'/database.sql:',
            '   mysql -u ПОЛЬЗОВАТЕЛЬ -p ИМЯ_БАЗЫ < '.self::META.'/database.sql',
            '   или импорт этого файла в phpMyAdmin. Таблицы с теми же именами будут заменены.',
            '4. Если копия без vendor:  composer install --no-dev',
            '5. Затем:  php artisan storage:link  и  php artisan optimize:clear',
            '6. Проверьте .env: домен, база, почта. Копия хранит .env того сайта, где она снята.',
            '',
        ]);
    }
}
