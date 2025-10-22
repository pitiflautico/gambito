<?php

namespace App\Jobs;

use App\Models\GameMatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Job para sincronizar partidas activas desde Redis a la base de datos
 *
 * Este job se ejecuta periódicamente (cada minuto) para asegurar que
 * el estado de las partidas activas en Redis esté respaldado en la BD.
 */
class SyncActiveMatchesToDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de reintentos
     */
    public $tries = 3;

    /**
     * Timeout en segundos
     */
    public $timeout = 120;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('[SyncMatches] Iniciando sincronización de partidas activas');

        $syncedCount = 0;
        $errorCount = 0;

        // Obtener partidas activas (iniciadas hace menos de 24 horas y no finalizadas)
        $activeMatches = GameMatch::whereNull('finished_at')
            ->whereNotNull('started_at')
            ->where('started_at', '>', now()->subHours(24))
            ->get();

        Log::info("[SyncMatches] {$activeMatches->count()} partidas activas encontradas");

        foreach ($activeMatches as $match) {
            try {
                $cacheKey = "game:match:{$match->id}:state";

                if (Cache::has($cacheKey)) {
                    $state = Cache::get($cacheKey);

                    // Verificar que el estado es válido
                    if (is_array($state) && !empty($state)) {
                        $match->game_state = $state;
                        $match->saveQuietly(); // Sin eventos para evitar overhead
                        $syncedCount++;

                        Log::debug("[SyncMatches] Match {$match->id} sincronizado");
                    }
                } else {
                    Log::debug("[SyncMatches] Match {$match->id} no tiene estado en Redis");
                }
            } catch (\Exception $e) {
                $errorCount++;
                Log::error("[SyncMatches] Error sincronizando match {$match->id}: {$e->getMessage()}");
            }
        }

        Log::info("[SyncMatches] Sincronización completada: {$syncedCount} exitosas, {$errorCount} errores");
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[SyncMatches] Job falló: ' . $exception->getMessage());
    }
}
