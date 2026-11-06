<?php

namespace Nexor\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Nexor\Cms\Support\ActivityLogger;
use Nexor\Cms\Support\Backups\Backup;
use Nexor\Cms\Support\Backups\BackupRunner;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «Резервное копирование»: список копий, запуск, шаги, скачивание, удаление.
 *
 * Копия собирается шагами: панель создаёт её и зовёт `step`, пока статус не
 * станет «готово». Каждый шаг укладывается в несколько секунд — лимит
 * хостинга на время запроса копии не мешает.
 */
class BackupController extends ApiController
{
    public function index(): JsonResponse
    {
        $directory = Backup::directory();
        $free = @disk_free_space($directory);

        return response()->json([
            'data' => array_map($this->present(...), Backup::all()),
            'running' => Backup::running()?->id(),
            'free_space' => $free === false ? null : (int) $free,
            'directory' => $directory,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'database' => ['required', 'boolean'],
            'files' => ['required', Rule::in([Backup::FILES_ALL, Backup::FILES_UPLOADS, Backup::FILES_NONE])],
            'exclude_vendor' => ['boolean'],
            'part_mb' => ['nullable', 'integer', 'min:10', 'max:4096'],
        ], [], [
            'files' => 'файлы',
            'part_mb' => 'размер части',
        ]);

        if ($running = Backup::running()) {
            return $this->refuse('Уже создаётся копия от '.$running->data['created_at'].' — дождитесь её или удалите.', 409);
        }

        try {
            $backup = Backup::create($validated, $request->user()?->name);
        } catch (RuntimeException $exception) {
            return $this->refuse($exception->getMessage());
        }

        ActivityLogger::log('created', null, 'Резервная копия '.$backup->id());

        return response()->json(['data' => $this->present($backup)], 201);
    }

    /**
     * Следующий шаг работы над копией.
     */
    public function step(string $backup): JsonResponse
    {
        $found = $this->find($backup);

        // Две вкладки не должны писать один архив одновременно.
        $lock = Cache::lock('nexor.backup.'.$found->id(), 300);

        if (! $lock->get()) {
            return response()->json(['data' => $this->present($found), 'busy' => true]);
        }

        try {
            @set_time_limit(0);

            $found = (new BackupRunner(Backup::find($found->id()) ?? $found))
                ->step((float) config('nexor.backups.step_seconds', 8));
        } finally {
            $lock->release();
        }

        return response()->json(['data' => $this->present($found)]);
    }

    public function download(string $backup, int $part): BinaryFileResponse
    {
        $found = $this->find($backup);
        $path = $found->partPath($part);

        abort_if($found->isRunning() || $path === null || ! is_file($path), 404);

        return response()->download($path, basename($path), ['Content-Type' => 'application/gzip']);
    }

    public function destroy(string $backup): JsonResponse
    {
        $found = $this->find($backup);
        $found->delete();

        ActivityLogger::log('deleted', null, 'Резервная копия '.$found->id());

        return $this->ok('Копия удалена.');
    }

    protected function find(string $id): Backup
    {
        return Backup::find($id) ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Backup $backup): array
    {
        $data = $backup->data;
        $progress = $data['progress'];

        return [
            'id' => $backup->id(),
            'status' => $backup->isStalled() ? 'stalled' : $data['status'],
            'phase' => $data['phase'],
            'percent' => $this->percent($backup),
            'options' => $data['options'],
            'author' => $data['author'],
            'created_at' => $data['created_at'],
            'finished_at' => $data['finished_at'],
            'error' => $data['error'],
            'progress' => $progress,
            'size' => $backup->size(),
            'parts' => array_map(fn (array $part, int $index): array => [
                'name' => $part['name'],
                'size' => $part['size'],
                'url' => $backup->isRunning() ? null : route('admin.api.backups.download', [$backup->id(), $index]),
            ], $backup->parts(), array_keys($backup->parts())),
        ];
    }

    /**
     * Готовность в процентах: база — первая пятая часть, остальное — архив.
     */
    protected function percent(Backup $backup): int
    {
        $data = $backup->data;
        $progress = $data['progress'];

        if ($data['status'] === Backup::DONE) {
            return 100;
        }

        $database = $progress['tables_total'] > 0 ? $progress['tables_done'] / $progress['tables_total'] : 0;
        $files = $progress['bytes_total'] > 0 ? $progress['bytes_done'] / $progress['bytes_total'] : 0;

        $value = match ($data['phase']) {
            'scan' => 0,
            'database' => 20 * $database,
            'prepare' => 20,
            'archive' => 20 + 79 * min(1, $files),
            default => 0,
        };

        return (int) floor($value);
    }
}
