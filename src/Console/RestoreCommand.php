<?php

namespace Nexor\Cms\Console;

use Illuminate\Console\Command;
use Nexor\Cms\Support\Backups\Backup;
use Nexor\Cms\Support\Backups\Restore;
use Nexor\Cms\Support\Backups\RestoreRunner;

/**
 * Восстановление из копии в консоли — для больших сайтов и SSH.
 *
 * То же восстановление, что из панели, только без ограничения по времени
 * шага. Без `--force` спрашивает подтверждение: файлы и база заменяются.
 */
class RestoreCommand extends Command
{
    protected $signature = 'nexor:restore
                            {backup? : Идентификатор копии; без него — список готовых копий}
                            {--no-files : Не трогать файлы}
                            {--no-db : Не трогать базу}
                            {--with-env : Восстановить и .env из копии}
                            {--with-vendor : Восстановить vendor и node_modules, если они есть в копии}
                            {--force : Не спрашивать подтверждение}';

    protected $description = 'Восстановить сайт из резервной копии: файлы и базу';

    public function handle(): int
    {
        $id = $this->argument('backup');

        if ($id === null) {
            return $this->listBackups();
        }

        $backup = Backup::find((string) $id);

        if ($backup === null || $backup->data['status'] !== Backup::DONE) {
            $this->components->error('Готовой копии '.$id.' нет. Список: php artisan nexor:restore');

            return self::FAILURE;
        }

        $options = [
            'files' => ! $this->option('no-files'),
            'database' => ! $this->option('no-db'),
            'env' => (bool) $this->option('with-env'),
            'vendor' => (bool) $this->option('with-vendor'),
        ];

        if (! $options['files'] && ! $options['database']) {
            $this->components->error('С --no-files и --no-db восстанавливать нечего.');

            return self::FAILURE;
        }

        if (($current = Restore::current()) && $current->isRunning() && ! $current->isStalled()) {
            $this->components->error('Восстановление уже идёт из панели — дождитесь его или отмените.');

            return self::FAILURE;
        }

        $what = implode(' и ', array_filter([$options['files'] ? 'файлы' : null, $options['database'] ? 'база' : null]));

        if (! $this->option('force') && ! $this->confirm('Будут заменены '.$what.' сайта копией от '.$backup->data['created_at'].'. Продолжить?')) {
            $this->components->warn('Отменено.');

            return self::FAILURE;
        }

        $restore = Restore::start($backup, $options, 'console');
        $this->components->info('Восстанавливаю из '.$backup->id().'…');

        $restore = (new RestoreRunner($restore))->step(null);

        foreach ($restore->data['notes'] as $note) {
            $this->components->warn($note);
        }

        if ($restore->data['status'] !== Restore::DONE) {
            $this->components->error('Восстановление не завершено: '.($restore->data['error'] ?? 'неизвестная ошибка'));

            return self::FAILURE;
        }

        $progress = $restore->data['progress'];

        $this->components->twoColumnDetail('Файлов записано', (string) $progress['files_written']);
        $this->components->twoColumnDetail('Запросов к базе', (string) $progress['statements']);
        $this->components->info('Готово. Если копия с другого домена — php artisan nexor:license:bind с ключом на этот домен.');

        return self::SUCCESS;
    }

    protected function listBackups(): int
    {
        $ready = array_filter(Backup::all(), fn (Backup $backup): bool => $backup->data['status'] === Backup::DONE);

        if ($ready === []) {
            $this->components->info('Готовых копий нет. Создать: php artisan nexor:backup');

            return self::SUCCESS;
        }

        foreach ($ready as $backup) {
            $this->components->twoColumnDetail(
                $backup->id(),
                $backup->data['created_at'].' · '.number_format($backup->size() / 1048576, 1, ',', ' ').' МБ',
            );
        }

        return self::SUCCESS;
    }
}
