<?php

namespace Nexor\Cms\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nexor\Cms\Http\Controllers\Api\RestoreController;
use Nexor\Cms\Support\Backups\Restore;
use Nexor\Cms\Support\Backups\RestoreRunner;

/**
 * Один шаг восстановления — по токену, без сессии и без CSRF.
 *
 * Восстановление заменяет базу: таблицу сессий, пользователей, кеш. Шаг,
 * которому нужна сессия, упал бы ровно посреди работы. Поэтому маршрут живёт
 * вне группы `web`, а доступ даёт одноразовый токен, выданный при запуске
 * тому, у кого есть право на восстановление.
 */
class RestoreStepController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $restore = Restore::current();

        if ($restore === null || ! $restore->checkToken($request->input('token'))) {
            return response()->json(['message' => 'Токен восстановления недействителен — откройте страницу заново.'], 403);
        }

        // Две вкладки не должны распаковывать один архив одновременно. Блокировка
        // файлом, а не кешем: кеш в базе может исчезнуть посреди восстановления.
        $lock = fopen($restore->lockPath(), 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return response()->json(['data' => RestoreController::present($restore), 'busy' => true]);
        }

        try {
            @set_time_limit(0);
            ignore_user_abort(true);

            $restore = (new RestoreRunner(Restore::current() ?? $restore))
                ->step((float) config('nexor.backups.step_seconds', 8));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return response()->json(['data' => RestoreController::present($restore)]);
    }
}
