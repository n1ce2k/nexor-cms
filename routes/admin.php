<?php

use Illuminate\Support\Facades\Route;
use Nexor\Cms\Http\Controllers\Auth\LoginController;
use Nexor\Cms\Http\Controllers\Auth\LoginLinkController;
use Nexor\Cms\Http\Controllers\PanelController;

Route::middleware('guest')->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store')->middleware('throttle:10,1');
});

Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

/*
 * Signed one-time sign-in, for local development only. The controller refuses
 * to run in production or with the config switch off; the signature makes the
 * URL impossible to forge.
 */
if (LoginLinkController::enabled()) {
    Route::get('login-link/{account}', LoginLinkController::class)
        ->name('login-link')
        ->middleware('signed');
}

// Панель на Vue: один документ на любой адрес под префиксом, маршрутизация —
// в браузере. Регистрируется последней, чтобы вход и выход выше не перекрывались.
Route::middleware('nexor.admin')->group(function (): void {
    Route::get('{any?}', PanelController::class)
        ->where('any', '.*')
        ->name('panel');
});
