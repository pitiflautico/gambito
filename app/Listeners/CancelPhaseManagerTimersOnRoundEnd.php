<?php

namespace App\Listeners;

use App\Events\Game\RoundEndedEvent;
use App\Services\Modules\TurnSystem\PhaseManager;
use Illuminate\Support\Facades\Log;

/**
 * Listener para cancelar timers de PhaseManager cuando termina una ronda.
 *
 * Este listener se ejecuta automáticamente cuando se emite RoundEndedEvent,
 * permitiendo que PhaseManager se autogestione sin necesidad de que el juego
 * lo cancele manualmente.
 *
 * Usado por: Mentiroso (juego con múltiples fases por ronda)
 */
class CancelPhaseManagerTimersOnRoundEnd
{
    /**
     * Handle the event.
     */
    public function handle(RoundEndedEvent $event): void
    {
        $match = $event->match;
        $gameState = $match->game_state;

        // Verificar si el juego usa PhaseManager
        if (!isset($gameState['phase_manager'])) {
            return; // Este juego no usa PhaseManager
        }

        Log::info("[PhaseManager Listener] Cancelling timers on round end", [
            'match_id' => $match->id,
            'game_slug' => $match->room->game_slug
        ]);

        try {
            // Reconstruir PhaseManager desde game_state
            $phaseManagerData = $gameState['phase_manager'];
            $phaseManager = PhaseManager::fromArray($phaseManagerData);

            // Cancelar todos los timers
            $phaseManager->cancelAllTimers();

            // Guardar el estado actualizado
            $gameState['phase_manager'] = $phaseManager->toArray();
            $match->game_state = $gameState;
            $match->save();

            Log::info("[PhaseManager Listener] Timers cancelled successfully", [
                'match_id' => $match->id
            ]);
        } catch (\Exception $e) {
            Log::error("[PhaseManager Listener] Error cancelling timers", [
                'match_id' => $match->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
