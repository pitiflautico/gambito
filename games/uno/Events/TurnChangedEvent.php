<?php

namespace App\Events\Uno;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento emitido cuando cambia el turno
 */
class TurnChangedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public GameMatch $match;
    public int $currentPlayerId;

    public function __construct(GameMatch $match, int $currentPlayerId)
    {
        $this->match = $match;
        $this->currentPlayerId = $currentPlayerId;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->match->room->code}");
    }

    public function broadcastAs(): string
    {
        return '.uno.turn.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'current_player_id' => $this->currentPlayerId,
            'game_state' => $this->match->fresh()->game_state,
        ];
    }
}
