<?php

namespace App\Listeners;

use App\Events\Game\PlayerActionEvent;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use Illuminate\Support\Facades\Log;

/**
 * Listener para procesar acciones de juego desde WebSocket.
 *
 * Este listener procesa acciones del jugador, las ejecuta en el engine
 * y emite un PlayerActionEvent con el resultado.
 *
 * Fase 3: Implementación Backend WebSocket Bidireccional
 */
class HandleClientGameAction
{
    /**
     * Handle the event.
     *
     * @param mixed $event Evento que contiene room_code, player_id, action, data
     * @return array Resultado de la acción
     */
    public function handle($event): array
    {
        // Extraer datos del evento (soporte para diferentes tipos de eventos)
        $roomCode = $event->roomCode ?? $event->room_code ?? null;
        $playerId = $event->playerId ?? $event->player_id ?? null;
        $action = $event->action ?? null;
        $data = $event->data ?? [];

        Log::info('[HandleClientGameAction] Processing action', [
            'room_code' => $roomCode,
            'player_id' => $playerId,
            'action' => $action,
            'data' => $data,
        ]);

        // Validar parámetros
        if (!$roomCode || !$playerId || !$action) {
            $error = 'Missing required parameters (room_code, player_id, action)';
            Log::error('[HandleClientGameAction] ' . $error);
            return [
                'success' => false,
                'message' => $error,
            ];
        }

        // Buscar sala
        $room = Room::where('code', strtoupper($roomCode))->first();

        if (!$room) {
            Log::warning('[HandleClientGameAction] Room not found', ['room_code' => $roomCode]);
            return [
                'success' => false,
                'message' => 'Sala no encontrada',
            ];
        }

        // Verificar que la sala esté en juego
        if ($room->status !== Room::STATUS_PLAYING || !$room->match) {
            Log::warning('[HandleClientGameAction] Room not in playing status', [
                'room_code' => $roomCode,
                'status' => $room->status,
            ]);
            return [
                'success' => false,
                'message' => 'La sala no está en juego',
            ];
        }

        $match = $room->match;

        // Obtener jugador desde cache del estado (Fase 1 optimization)
        $player = $this->getPlayerFromCache($match, $playerId);

        if (!$player) {
            // Fallback: buscar en BD (backward compatibility)
            $player = Player::find($playerId);
        }

        if (!$player) {
            Log::warning('[HandleClientGameAction] Player not found', [
                'player_id' => $playerId,
                'room_code' => $roomCode,
            ]);
            return [
                'success' => false,
                'message' => 'Jugador no encontrado',
            ];
        }

        // Verificar que el jugador pertenece a este match
        if ($player->match_id !== $match->id) {
            Log::warning('[HandleClientGameAction] Player not in this match', [
                'player_id' => $playerId,
                'player_match_id' => $player->match_id,
                'room_match_id' => $match->id,
            ]);
            return [
                'success' => false,
                'message' => 'El jugador no pertenece a esta partida',
            ];
        }

        try {
            // Procesar la acción usando el engine del match
            $result = $match->processAction(
                player: $player,
                action: $action,
                data: $data
            );

            Log::info('[HandleClientGameAction] Action processed successfully', [
                'room_code' => $roomCode,
                'player_id' => $playerId,
                'action' => $action,
                'success' => $result['success'] ?? false,
            ]);

            // Emitir evento de resultado de acción
            event(new PlayerActionEvent(
                match: $match,
                player: $player,
                actionType: $action,
                actionData: array_merge(['result' => $result], $data),
                success: $result['success'] ?? false
            ));

            return $result;

        } catch (\Exception $e) {
            Log::error('[HandleClientGameAction] Error processing action', [
                'room_code' => $roomCode,
                'player_id' => $playerId,
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $errorResult = [
                'success' => false,
                'message' => 'Error al procesar acción: ' . $e->getMessage(),
            ];

            // Emitir evento de error
            event(new PlayerActionEvent(
                match: $match,
                player: $player,
                actionType: $action,
                actionData: ['error' => $e->getMessage()],
                success: false
            ));

            return $errorResult;
        }
    }

    /**
     * Obtener jugador desde el cache del game_state (Fase 1 optimization)
     *
     * @param GameMatch $match
     * @param int $playerId
     * @return Player|null
     */
    private function getPlayerFromCache(GameMatch $match, int $playerId): ?Player
    {
        $playerData = $match->game_state['_config']['players'][$playerId] ?? null;

        if (!$playerData) {
            return null;
        }

        // Crear Player object desde datos en memoria (NO query!)
        $player = new Player();
        $player->id = $playerData['id'];
        $player->name = $playerData['name'];
        $player->user_id = $playerData['user_id'];
        $player->avatar = $playerData['avatar'] ?? null;
        $player->match_id = $match->id; // ← Importante para la validación
        $player->exists = true;

        return $player;
    }
}
