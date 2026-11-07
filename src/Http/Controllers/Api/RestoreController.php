<?php

namespace Nexor\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nexor\Cms\Support\ActivityLogger;
use Nexor\Cms\Support\Backups\Backup;
use Nexor\Cms\Support\Backups\Restore;
use RuntimeException;

/**
 * «Восстановление»: запуск, продолжение и отмена, загрузка архива с компьютера.
 *
 * Сами шаги идут не сюда, а в `RestoreStepController` — по токену, без сессии:
 * восстановление заменяет базу вместе с таблицей сессий и пользователями.
 */
class RestoreController extends ApiController
{
    public function show(): JsonResponse
    {
        $restore = Restore::current();

        return response()->json([
            'data' => $restore ? self::present($restore) : null,
            'step_url' => route('admin.restore.step'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'backup' => ['required', 'string'],
            'files' => ['required', 'boolean'],
            'database' => ['required', 'boolean'],
            'env' => ['boolean'],
            'vendor' => ['boolean'],
        ]);

        $backup = Backup::find($validated['backup']);

        if ($backup === null || $backup->data['status'] !== Backup::DONE) {
            return $this->refuse('Копия не найдена или ещё не готова.');
        }

        if (! $validated['files'] && ! $validated['database']) {
            return $this->refuse('Выберите, что восстановить: файлы, базу или и то, и другое.');
        }

        if ($validated['files'] && ($problem = Restore::rootProblem())) {
            return $this->refuse($problem);
        }

        if (($current = Restore::current()) && $current->isRunning() && ! $current->isStalled()) {
            return $this->refuse('Восстановление уже идёт — дождитесь его или отмените.', 409);
        }

        if (Backup::running()) {
            return $this->refuse('Сейчас создаётся резервная копия — восстановление после неё.', 409);
        }

        // Журнал пишется до старта: после замены базы этой записи уже не будет.
        ActivityLogger::log('updated', null, 'Восстановление из копии '.$backup->id());

        $restore = Restore::start($backup, $validated, $request->user()?->name);

        return response()->json([
            'data' => self::present($restore),
            'token' => $restore->issueToken(),
            'step_url' => route('admin.restore.step'),
        ], 201);
    }

    /**
     * Продолжить прерванное: новая вкладка получает новый токен.
     */
    public function resume(): JsonResponse
    {
        $restore = Restore::current();

        if ($restore === null || ! $restore->isRunning()) {
            return $this->refuse('Продолжать нечего.');
        }

        return response()->json([
            'data' => self::present($restore),
            'token' => $restore->issueToken(),
            'step_url' => route('admin.restore.step'),
        ]);
    }

    /**
     * Отменить идущее восстановление или убрать с экрана итог прошлого.
     */
    public function destroy(): JsonResponse
    {
        $restore = Restore::current();

        if ($restore?->isRunning()) {
            $restore->finish(Restore::CANCELLED);

            return $this->ok('Восстановление остановлено. Уже восстановленные файлы и таблицы остались как есть.');
        }

        @unlink(Restore::file());
        @unlink(Backup::directory().DIRECTORY_SEPARATOR.'restore.lock');

        return $this->ok('Готово.');
    }

    /**
     * Начать загрузку архива с компьютера: тома по порядку.
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:999'],
            'files.*.name' => ['required', 'string', 'max:255'],
            'files.*.size' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $backup = Backup::createUpload($validated['files'], $request->user()?->name);
        } catch (RuntimeException $exception) {
            return $this->refuse($exception->getMessage());
        }

        return response()->json(['data' => ['id' => $backup->id()]], 201);
    }

    /**
     * Кусок тома. Ответ — сколько байт тома уже на сервере.
     */
    public function chunk(Request $request, string $backup): JsonResponse
    {
        $request->validate([
            'part' => ['required', 'integer', 'min:0'],
            'offset' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
        ]);

        $found = Backup::find($backup);

        if ($found === null || $found->data['status'] !== Backup::UPLOADING) {
            return $this->refuse('Загрузка не найдена или уже завершена.', 404);
        }

        try {
            $received = $found->appendUpload(
                (int) $request->input('part'),
                (int) $request->input('offset'),
                (string) file_get_contents($request->file('chunk')->getRealPath()),
            );
        } catch (RuntimeException $exception) {
            return $this->refuse($exception->getMessage());
        }

        return response()->json([
            'received' => $received,
            'status' => Backup::find($backup)?->data['status'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Restore $restore): array
    {
        $data = $restore->data;
        $progress = $data['progress'];
        $withDatabase = $data['options']['database'];

        $extracted = $progress['compressed_total'] > 0 ? $progress['compressed_done'] / $progress['compressed_total'] : 0;
        $executed = $progress['sql_total'] > 0 ? $progress['sql_done'] / $progress['sql_total'] : 0;

        $percent = match ($data['phase']) {
            'extract' => ($withDatabase ? 70 : 99) * min(1, $extracted),
            'database' => 70 + 29 * min(1, $executed),
            'finish' => 99,
            'done' => 100,
            default => 0,
        };

        return [
            'status' => $restore->isStalled() ? 'stalled' : $data['status'],
            'phase' => $data['phase'],
            'percent' => (int) floor($percent),
            'backup' => $data['backup'],
            'backup_created_at' => $data['backup_created_at'],
            'options' => $data['options'],
            'progress' => $progress,
            'notes' => array_values(array_unique($data['notes'])),
            'error' => $data['error'],
            'author' => $data['author'],
            'started_at' => $data['started_at'],
            'finished_at' => $data['finished_at'],
        ];
    }
}
