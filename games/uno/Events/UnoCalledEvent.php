<?php

namespace App\Events\Uno;

use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento emitido cuando un jugador declara UNO
 */
class UnoCalledEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public GameMatch $match;
    public Player $player;

    public function __construct(GameMatch $match, Player $player)
    {
        $this->match = $match;
        $this->player = $player;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->match->room->code}");
    }

    public function broadcastAs(): string
    {
        return '.uno.uno.called';
    }

    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->player->id,
            'player_name' => $this->player->user->name,
            'game_state' => $this->match->fresh()->game_state,
        ];
    }
}
