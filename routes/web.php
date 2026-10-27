<?php

/*
 * Trasy wtyczki. Samą stronę obsługuje trasa LibreNMS /plugin/{plugin}
 * przez SinglePageHook (Hooks\Page). Tu jest API JSON i pobieranie kopii zapasowych.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

use Illuminate\Support\Facades\Route;
use Ispnetella\NetellaReg\Http\ApiController;
use Ispnetella\NetellaReg\Http\BackupController;
use Ispnetella\NetellaReg\Plugin;

Route::middleware(['web', 'auth'])
    ->prefix('plugin/' . Plugin::NAME . '/api')
    ->name('plugin.netella-reg.')
    ->group(function (): void {
        Route::match(['GET', 'POST'], '{module}/{action}', ApiController::class)
            ->where(['module' => '[a-z][a-z0-9_]{1,31}', 'action' => '[a-z][a-z0-9_-]{0,63}'])
            ->name('api');
    });

Route::middleware(['web', 'auth'])
    ->get('plugin/' . Plugin::NAME . '/backup/{file}', BackupController::class)
    ->where('file', 'netella-reg-[0-9]{8}-[0-9]{4,6}-(auto|manual|pre-restore)\.json\.gz')
    ->name('plugin.netella-reg.backup');
