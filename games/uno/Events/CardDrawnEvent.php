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
 * Evento emitido cuando un jugador roba carta(s)
 */
class CardDrawnEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public GameMatch $match;
    public Player $player;
    public int $cardCount;

    public function __construct(GameMatch $match, Player $player, int $cardCount)
    {
        $this->match = $match;
        $this->player = $player;
        $this->cardCount = $cardCount;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->match->room->code}");
    }

    public function broadcastAs(): string
    {
        return '.uno.card.drawn';
    }

    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->player->id,
            'player_name' => $this->player->user->name,
            'card_count' => $this->cardCount,
            'game_state' => $this->match->fresh()->game_state,
        ];
    }
}
