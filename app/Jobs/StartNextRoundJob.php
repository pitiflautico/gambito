<?php

namespace App\Jobs;

use App\Models\GameMatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job para iniciar la siguiente ronda con delay (BACKUP).
 *
 * Este job actúa como BACKUP del frontend. Normalmente, el frontend
 * llama al endpoint /api/rooms/{code}/next-round después del countdown,
 * pero si todos los clientes se desconectan, este job asegura que
 * el juego continúe.
 *
 * El job usa el sistema de locks de GameMatch para evitar duplicados:
 * - Si el frontend ya inició la ronda, el lock estará ocupado y este job no hace nada
 * - Si el frontend falló, este job adquiere el lock e inicia la ronda
 */
class StartNextRoundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ID del match a avanzar.
     */
    public int $matchId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $matchId)
    {
        $this->matchId = $matchId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('⏰ [StartNextRoundJob] Attempting to start next round (BACKUP)', [
            'match_id' => $this->matchId
        ]);

        $match = GameMatch::find($this->matchId);

        if (!$match) {
            Log::warning('⚠️ [StartNextRoundJob] Match not found', [
                'match_id' => $this->matchId
            ]);
            return;
        }

        // Intentar adquirir lock
        if (!$match->acquireRoundLock()) {
            Log::info('✅ [StartNextRoundJob] Frontend already processed - backup not needed', [
                'match_id' => $this->matchId
            ]);
            return;
        }

        try {
            Log::info('🔄 [StartNextRoundJob] Frontend did not respond - executing backup', [
                'match_id' => $this->matchId
            ]);

            // Iniciar siguiente ronda
            $engine = $match->getEngine();
            $engine->handleNewRound($match);

            // Liberar lock
            $match->releaseRoundLock();

            Log::info('✅ [StartNextRoundJob] Round started successfully via backup', [
                'match_id' => $this->matchId
            ]);
        } catch (\Exception $e) {
            // Liberar lock en caso de error
            $match->releaseRoundLock();

            Log::error('❌ [StartNextRoundJob] Error starting round', [
                'match_id' => $this->matchId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }
}
