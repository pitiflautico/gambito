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
 * Evento emitido cuando un jugador gana una ronda
 */
class PlayerWonEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public GameMatch $match;
    public Player $player;
    public int $pointsEarned;

    public function __construct(GameMatch $match, Player $player, int $pointsEarned)
    {
        $this->match = $match;
        $this->player = $player;
        $this->pointsEarned = $pointsEarned;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->match->room->code}");
    }

    public function broadcastAs(): string
    {
        return '.uno.player.won';
    }

    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->player->id,
            'player_name' => $this->player->user->name,
            'points_earned' => $this->pointsEarned,
            'game_state' => $this->match->fresh()->game_state,
        ];
    }
}
