<?php

namespace App\Listeners;

use App\Events\Game\StartNewRoundEvent;
use App\Models\GameMatch;
use Illuminate\Support\Facades\Log;

/**
 * Listener que maneja el inicio de una nueva ronda.
 *
 * Este listener:
 * 1. Carga el match
 * 2. Obtiene el Engine del juego
 * 3. Llama a Engine->handleNewRound() que:
 *    - Avanza la ronda
 *    - Emite RoundStartedEvent
 *    - Inicia la primera fase
 */
class HandleStartNewRound
{
    public function handleStartNewRound(StartNewRoundEvent $event): void
    {
        $handlerId = uniqid('handler_', true);

        // RACE CONDITION FIX: Usar lock distribuido atómico
        // Solo permite UNA ejecución por match a la vez
        $lock = \Cache::lock("start_new_round:{$event->matchId}", 10);

        if (!$lock->get()) {
            Log::warning('⚠️ [HandleStartNewRound] Could not obtain lock - skipping duplicate execution', [
                'handler_id' => $handlerId,
                'match_id' => $event->matchId,
                'room_code' => $event->roomCode
            ]);
            return;
        }

        try {
            Log::info('🎯 [HandleStartNewRound] Starting new round', [
                'handler_id' => $handlerId,
                'match_id' => $event->matchId,
                'room_code' => $event->roomCode
            ]);

            // Cargar match con relaciones (room->game)
            $match = GameMatch::with('room.game')->findOrFail($event->matchId);

            // Obtener game model
            $game = $match->room->game;

            // Obtener Engine class desde Game model
            $engineClass = $game->getEngineClass();
            if (!$engineClass || !class_exists($engineClass)) {
                Log::error('❌ [HandleStartNewRound] Engine not found', [
                    'game_slug' => $game->slug,
                    'engine_class' => $engineClass
                ]);
                return;
            }

            $engine = app($engineClass);

            // Llamar a handleNewRound() del Engine
            // Esto avanzará la ronda y emitirá RoundStartedEvent
            $engine->handleNewRound($match);

            Log::info('✅ [HandleStartNewRound] New round started', [
                'handler_id' => $handlerId,
                'match_id' => $match->id
            ]);
        } finally {
            // Siempre liberar el lock
            $lock->release();
        }
    }
}
