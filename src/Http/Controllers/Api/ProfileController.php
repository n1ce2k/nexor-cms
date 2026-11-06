<?php

namespace Nexor\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Nexor\Cms\Http\Requests\ProfileRequest;
use Nexor\Cms\Http\Resources\UserResource;
use Nexor\Cms\Support\ActivityLogger;
use Nexor\Cms\Support\Nexor;
use Nexor\Cms\Support\Uploads;

/**
 * Свой профиль: имя, контакты, аватар и пароль.
 *
 * Отдельно от раздела «Пользователи»: сменить свой пароль должен мочь любой,
 * кто входит в панель, а не только тот, кому доверено управлять людьми.
 */
class ProfileController extends ApiController
{
    public function show(Request $request): UserResource
    {
        return UserResource::make($request->user()->load('roles'));
    }

    public function update(ProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->fill($request->safe()->except(['avatar', 'avatar_remove']));
        $user->avatar = Uploads::handle($request, 'avatar', $user->avatar, Nexor::directory('avatars'));
        $user->save();

        ActivityLogger::updated($user, 'Профиль обновлён');

        return UserResource::make($user->fresh('roles'))
            ->additional(['message' => 'Профиль сохранён.'])
            ->response();
    }

    public function password(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [], [
            'current_password' => 'текущий пароль',
            'password' => 'новый пароль',
        ]);

        $request->user()->forceFill(['password' => Hash::make($validated['password'])])->save();

        ActivityLogger::log('updated', $request->user(), 'Смена пароля');

        return $this->ok('Пароль изменён.');
    }
}
