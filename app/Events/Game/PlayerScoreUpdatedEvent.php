<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento genérico emitido cuando se actualiza el score de un jugador.
 *
 * Este evento se usa para actualizar la UI en tiempo real mostrando
 * el score actual de cada jugador.
 *
 * CUÁNDO SE EMITE:
 * - Cuando un jugador gana puntos (acierta, completa objetivo, etc.)
 * - Cuando un jugador pierde puntos (penalización, error, etc.)
 * - Cuando se ajusta manualmente el score
 *
 * USO EN FRONTEND:
 * BaseGameClient escucha este evento y actualiza automáticamente this.scores
 */
class PlayerScoreUpdatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;
    public int $newScore;
    public int $pointsEarned;

    public function __construct(
        GameMatch $match,
        int $playerId,
        int $newScore,
        int $pointsEarned
    ) {
        $this->roomCode = $match->room->code;
        $this->playerId = $playerId;
        $this->newScore = $newScore;
        $this->pointsEarned = $pointsEarned;

        \Log::info('[PlayerScoreUpdatedEvent] Score updated', [
            'room_code' => $this->roomCode,
            'player_id' => $playerId,
            'new_score' => $newScore,
            'points_earned' => $pointsEarned,
        ]);
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'player.score.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'new_score' => $this->newScore,
            'points_earned' => $this->pointsEarned,
        ];
    }
}
