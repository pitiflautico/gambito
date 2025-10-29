<?php

namespace App\Listeners;

use App\Events\Game\PhaseChangedEvent;
use App\Events\Game\PhaseTimerExpiredEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Listener GENÉRICO para manejar la expiración de timers de fase.
 *
 * Este listener maneja automáticamente el flujo de fases para todos los juegos:
 * 1. Lee config.json para obtener on_end del la fase que expiró
 * 2. Emite el evento on_end si existe
 * 3. Avanza a la siguiente fase
 * 4. PhaseManager emitirá automáticamente on_start de la nueva fase
 */
class HandleGenericPhaseTimerExpired
{
    /**
     * Handle the event.
     */
    public function handle(PhaseTimerExpiredEvent $event): void
    {
        Log::info("⏰ [GENERIC] PhaseTimerExpired", [
            'phase' => $event->phaseName,
            'matchId' => $event->matchId,
            'phaseIndex' => $event->phaseIndex ?? null
        ]);

        if ($event->matchId === null) {
            Log::warning("❌ [GENERIC] matchId is null - ignoring");
            return;
        }

        // 🔒 LOCK: Prevenir race condition
        $lockKey = "generic-phase-timer:{$event->matchId}:{$event->phaseName}";
        $lock = Cache::lock($lockKey, 5);

        if (!$lock->get()) {
            Log::info("🔒 [GENERIC] Already being processed");
            return;
        }

        try {
            $match = \App\Models\GameMatch::find($event->matchId);

            if (!$match) {
                Log::error("❌ [GENERIC] Match not found", ['matchId' => $event->matchId]);
                return;
            }

            // Obtener config del juego
            $config = $this->getGameConfig($match);
            if (!isset($config['modules']['phase_system']['enabled']) ||
                !$config['modules']['phase_system']['enabled']) {
                return;
            }

            $phases = $config['modules']['phase_system']['phases'] ?? [];
            if (empty($phases)) {
                Log::warning("❌ [GENERIC] No phases configured");
                return;
            }

            // Encontrar fase que expiró
            $phaseIndex = $event->phaseIndex ?? null;
            $expiredPhaseConfig = $phases[$phaseIndex] ?? null;

            if (!$expiredPhaseConfig) {
                Log::warning("❌ [GENERIC] Phase config not found", [
                    'phaseIndex' => $phaseIndex,
                    'phase' => $event->phaseName
                ]);
                return;
            }

            Log::info("✅ [GENERIC] Processing phase expiration", [
                'match_id' => $match->id,
                'phase' => $event->phaseName,
                'on_end' => $expiredPhaseConfig['on_end'] ?? null
            ]);

            // 1. EMITIR on_end si está configurado
            $onEndEvent = $expiredPhaseConfig['on_end'] ?? null;
            if ($onEndEvent && class_exists($onEndEvent)) {
                Log::info("🎯 [GENERIC] Emitting on_end event", [
                    'event' => $onEndEvent,
                    'phase' => $event->phaseName
                ]);

                event(new $onEndEvent($match, $expiredPhaseConfig));
            }

            // 2. VERIFICAR si es la última fase
            $nextPhaseIndex = $phaseIndex + 1;
            $isLastPhase = $nextPhaseIndex >= count($phases);

            if ($isLastPhase) {
                Log::info("🏁 [GENERIC] Last phase completed", [
                    'match_id' => $match->id,
                    'phase' => $event->phaseName
                ]);
                // Última fase - el juego debe manejar fin de ronda
                return;
            }

            // 3. AVANZAR A LA SIGUIENTE FASE usando PhaseManager
            Log::info("➡️ [GENERIC] Advancing to next phase via PhaseManager", [
                'match_id' => $match->id,
                'from_phase' => $event->phaseName,
                'to_phase_index' => $nextPhaseIndex
            ]);

            // Obtener PhaseManager desde game_state
            if (!isset($match->game_state['turn_system'])) {
                Log::error("❌ [GENERIC] turn_system not found in game_state");
                return;
            }

            // Reconstruir PhaseManager desde game_state
            $turnSystemData = $match->game_state['turn_system'];
            $phaseManager = \App\Services\Modules\TurnSystem\PhaseManager::fromArray($turnSystemData);
            $phaseManager->setMatch($match);

            // nextPhase() hace todo automáticamente:
            // - Avanza el índice de fase
            // - Emite on_start de la nueva fase
            // - Inicia el timer automáticamente
            $phaseInfo = $phaseManager->nextPhase();

            Log::info("✅ [GENERIC] Advanced to new phase", [
                'new_phase' => $phaseInfo['phase_name'],
                'duration' => $phaseInfo['duration']
            ]);

            // Guardar estado actualizado
            $gameState = $match->game_state;
            $gameState['turn_system'] = $phaseManager->toArray();

            $timerService = $phaseManager->getTimerService();
            if ($timerService) {
                $timerData = $timerService->toArray();
                $gameState['timer_system'] = $timerData['timer_system'] ?? [];
            }

            $match->game_state = $gameState;
            $match->save();

            // 4. EMITIR PhaseChangedEvent para notificar al frontend
            $timing = [
                'server_time' => now()->timestamp,
                'duration' => $phaseInfo['duration']
            ];

            Log::info("🔄 [GENERIC] Emitting PhaseChangedEvent", [
                'match_id' => $match->id,
                'new_phase' => $phaseInfo['phase_name'],
                'previous_phase' => $event->phaseName
            ]);

            event(new PhaseChangedEvent(
                match: $match,
                newPhase: $phaseInfo['phase_name'],
                previousPhase: $event->phaseName,
                additionalData: [
                    'phase' => $phaseInfo['phase_name'],
                    'timing' => $timing
                ]
            ));

        } finally {
            $lock->release();
        }
    }

    private function getGameConfig(\App\Models\GameMatch $match): array
    {
        $gameSlug = $match->room->game->slug;
        $configPath = base_path("games/{$gameSlug}/config.json");

        if (!file_exists($configPath)) {
            return [];
        }

        return json_decode(file_get_contents($configPath), true) ?? [];
    }

    private function getEngineClass(\App\Models\GameMatch $match): string
    {
        $gameSlug = $match->room->game->slug;
        return "Games\\" . ucfirst($gameSlug) . "\\" . ucfirst($gameSlug) . "Engine";
    }
}
