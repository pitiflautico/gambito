<?php

namespace App\Listeners\Mentiroso;

use App\Events\Game\PhaseTimerExpiredEvent;
use Illuminate\Support\Facades\Log;

/**
 * Listener para manejar la expiración de timers de fase en Mentiroso.
 *
 * Este listener se ejecuta cuando PhaseManager emite PhaseTimerExpiredEvent,
 * y delega al engine del juego para que decida qué hacer en cada fase.
 */
class HandlePhaseTimerExpired
{

    /**
     * Handle the event.
     */
    public function handle(PhaseTimerExpiredEvent $event): void
    {
        Log::info("⏰ [TIMER EXPIRED] Phase timer expired", [
            'phase' => $event->phaseName,
            'matchId' => $event->matchId
        ]);

        // Validar que matchId no sea null
        if ($event->matchId === null) {
            Log::warning("❌ [TIMER EXPIRED] matchId is null - ignoring event", [
                'phase' => $event->phaseName
            ]);
            return;
        }

        // 🔒 LOCK: Prevenir race condition cuando múltiples workers procesan el mismo timer
        $lockKey = "phase-timer-expired:{$event->matchId}:{$event->phaseName}";

        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 5);

        if (!$lock->get()) {
            Log::info("🔒 [TIMER EXPIRED] Already being processed by another worker", [
                'matchId' => $event->matchId,
                'phase' => $event->phaseName
            ]);
            return;
        }

        try {
            // Cargar match desde el matchId
            $match = \App\Models\GameMatch::find($event->matchId);

            if (!$match) {
                Log::error("❌ [TIMER EXPIRED] Match not found", [
                    'matchId' => $event->matchId
                ]);
                return;
            }

            // Solo procesar si es un juego Mentiroso
            if ($match->room->game->slug !== 'mentiroso') {
                return;
            }

            // Verificar que la fase actual coincide con la fase del timer
            // Si ya avanzó a otra fase, ignorar este timer
            $currentPhase = $match->game_state['current_phase'] ?? null;
            if ($currentPhase !== $event->phaseName) {
                Log::info("⏭️ [TIMER EXPIRED] Phase already changed, ignoring expired timer", [
                    'match_id' => $match->id,
                    'expired_phase' => $event->phaseName,
                    'current_phase' => $currentPhase
                ]);
                return;
            }

            Log::info("✅ [TIMER EXPIRED] Processing phase expiration", [
                'match_id' => $match->id,
                'phase' => $event->phaseName
            ]);

            // Obtener el engine del juego desde el slug
            $gameSlug = $match->room->game->slug;
            $engineClass = "Games\\" . ucfirst($gameSlug) . "\\" . ucfirst($gameSlug) . "Engine";

            if (!class_exists($engineClass)) {
                Log::error("[Mentiroso Listener] Engine class not found", [
                    'engine_class' => $engineClass
                ]);
                return;
            }

            $engine = new $engineClass();

            // Delegar al engine según la fase que expiró
            switch ($event->phaseName) {
                case 'preparation':
                    Log::info("🔄 [PHASE CHANGE] Calling onPreparationEnd", ['match_id' => $match->id]);
                    $engine->onPreparationEnd($match);
                    break;

                case 'persuasion':
                    Log::info("🔄 [PHASE CHANGE] Calling onPersuasionEnd", ['match_id' => $match->id]);
                    $engine->onPersuasionEnd($match);
                    break;

                case 'voting':
                    Log::info("🔄 [PHASE CHANGE] Calling onVotingEnd", ['match_id' => $match->id]);
                    $engine->onVotingEnd($match);
                    break;

                default:
                    Log::warning("❌ [TIMER EXPIRED] Unknown phase", [
                        'phase' => $event->phaseName
                    ]);
            }
        } finally {
            // Liberar el lock
            $lock->release();
        }
    }
}
