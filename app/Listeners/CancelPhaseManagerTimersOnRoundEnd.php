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

            // IMPORTANTE: Resetear las fases a la primera fase manualmente
            // No llamamos a reset() porque intentaría iniciar un timer (aunque fallará sin TimerService)
            // Mejor hacerlo explícitamente para claridad
            $phaseManagerData['current_turn_index'] = 0;
            $phaseManagerData['is_paused'] = false;
            $phaseManagerData['direction'] = 1;

            // Guardar el estado actualizado directamente
            $gameState['phase_manager'] = $phaseManagerData;
            $match->game_state = $gameState;
            $match->save();

            Log::info("[PhaseManager Listener] Timers cancelled and phases reset successfully", [
                'match_id' => $match->id,
                'reset_to_first_phase' => true
            ]);
        } catch (\Exception $e) {
            Log::error("[PhaseManager Listener] Error cancelling timers and resetting phases", [
                'match_id' => $match->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
