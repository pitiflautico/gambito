<?php

use App\Jobs\SyncActiveMatchesToDatabase;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ==================== SCHEDULER ====================

// Sincronizar partidas activas desde Redis a BD cada minuto
Schedule::job(new SyncActiveMatchesToDatabase)->everyMinute();

// TODO: Agregar más tareas programadas aquí:
// - Limpieza de partidas antiguas
// - Limpieza de game_events antiguos
// - Notificaciones de salas inactivas
