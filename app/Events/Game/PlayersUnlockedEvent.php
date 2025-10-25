<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento emitido cuando todos los jugadores son desbloqueados.
 * 
 * Típicamente ocurre al inicio de una nueva ronda.
 */
class PlayersUnlockedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public array $additionalData;

    public function __construct(
        GameMatch $match,
        array $additionalData = []
    ) {
        $this->roomCode = $match->room->code;
        $this->additionalData = $additionalData;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'game.players.unlocked';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => 'All players unlocked',
            'additional_data' => $this->additionalData,
        ];
    }
}
