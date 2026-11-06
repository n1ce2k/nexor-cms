<?php

namespace Nexor\Cms\Console;

use Illuminate\Console\Command;
use Nexor\Cms\Support\Backups\Backup;
use Nexor\Cms\Support\Backups\BackupRunner;
use RuntimeException;

/**
 * Резервная копия из консоли — для SSH и для расписания (cron).
 *
 * Та же копия, что из панели, только без ограничения по времени шага.
 */
class BackupCommand extends Command
{
    protected $signature = 'nexor:backup
                            {--files=all : Какие файлы: all — весь сайт, uploads — только загруженные (storage/app), none — без файлов}
                            {--no-db : Без дампа базы}
                            {--with-vendor : Вместе с vendor и node_modules}
                            {--part= : Резать архив на тома по столько МБ}
                            {--keep= : Оставить столько последних готовых копий, старые удалить}';

    protected $description = 'Создать резервную копию сайта: архив файлов и дамп базы';

    public function handle(): int
    {
        if ($running = Backup::running()) {
            $this->components->error('Уже создаётся копия '.$running->id().' — дождитесь её или удалите в панели.');

            return self::FAILURE;
        }

        $part = $this->option('part');

        if ($part !== null && (! ctype_digit((string) $part) || (int) $part < 10)) {
            $this->components->error('Размер тома — целое число мегабайт, не меньше 10.');

            return self::FAILURE;
        }

        try {
            $backup = Backup::create([
                'database' => ! $this->option('no-db'),
                'files' => $this->option('files'),
                'exclude_vendor' => ! $this->option('with-vendor'),
                'part_mb' => $part === null ? null : (int) $part,
            ], 'console');
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Создаю копию '.$backup->id().'…');

        $backup = (new BackupRunner($backup))->step(null);

        if ($backup->data['status'] !== Backup::DONE) {
            $this->components->error('Копия не создана: '.($backup->data['error'] ?? 'неизвестная ошибка'));

            return self::FAILURE;
        }

        $progress = $backup->data['progress'];

        $this->components->twoColumnDetail('Файлов', (string) $progress['files_done']);
        $this->components->twoColumnDetail('Таблиц', (string) $progress['tables_total']);

        foreach ($backup->parts() as $index => $volume) {
            $this->components->twoColumnDetail(
                Backup::directory().DIRECTORY_SEPARATOR.$volume['name'],
                number_format($volume['size'] / 1048576, 1, ',', ' ').' МБ',
            );
        }

        if ($progress['skipped'] > 0) {
            $this->components->warn('Пропущено файлов, которые не удалось прочитать: '.$progress['skipped']);
        }

        $this->prune();

        return self::SUCCESS;
    }

    /**
     * Удаляет старые готовые копии сверх `--keep`.
     */
    protected function prune(): void
    {
        $keep = $this->option('keep');

        if ($keep === null || ! ctype_digit((string) $keep)) {
            return;
        }

        $done = array_values(array_filter(Backup::all(), fn (Backup $backup): bool => $backup->data['status'] === Backup::DONE));

        foreach (array_slice($done, (int) $keep) as $old) {
            $old->delete();
            $this->components->twoColumnDetail('Удалена старая копия', $old->id());
        }
    }
}
