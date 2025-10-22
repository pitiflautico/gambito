<?php

namespace Games\Pictionary\Events;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento que se dispara cuando cambia el estado general del juego.
 *
 * Se usa para sincronizar:
 * - Cambios de fase (lobby → drawing → scoring → results)
 * - Cambios de turno/ronda
 * - Actualización de puntuaciones
 * - Timer/tiempo restante
 * - Cualquier cambio en game_state
 */
class GameStateUpdatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public array $gameState;
    public string $updateType;

    /**
     * Create a new event instance.
     *
     * @param GameMatch $match La partida actual
     * @param string $updateType Tipo de actualización (phase_change, turn_change, score_update, etc)
     */
    public function __construct(GameMatch $match, string $updateType = 'general')
    {
        $this->roomCode = $match->room->code;
        $this->gameState = $match->game_state;
        $this->updateType = $updateType;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('room.' . $this->roomCode),
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'pictionary.game.state.updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'update_type' => $this->updateType,
            'phase' => $this->gameState['phase'],
            'round' => $this->gameState['current_round'] ?? 1, // Desde TurnManager
            'rounds_total' => $this->gameState['total_rounds'] ?? 5, // Desde TurnManager
            'current_drawer_id' => $this->gameState['current_drawer_id'],
            'is_paused' => $this->gameState['game_is_paused'] ?? false, // Pausa del juego (no del TurnManager)
            'scores' => $this->gameState['scores'],
            'eliminated_this_round' => $this->gameState['temporarily_eliminated'] ?? [], // Desde TurnManager
            'pending_answer' => $this->gameState['pending_answer'],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
