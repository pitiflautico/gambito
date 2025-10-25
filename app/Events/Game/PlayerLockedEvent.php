<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento emitido cuando un jugador es bloqueado (ya no puede actuar en la ronda actual).
 * 
 * Se usa para notificar al cliente que debe mostrar un overlay de "bloqueado"
 * y esperar a que termine la ronda.
 */
class PlayerLockedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;
    public string $playerName;
    public array $additionalData;

    public function __construct(
        GameMatch $match,
        Player $player,
        array $additionalData = []
    ) {
        $this->roomCode = $match->room->code;
        $this->playerId = $player->id;
        $this->playerName = $player->name;
        $this->additionalData = $additionalData;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'game.player.locked';
    }

    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'player_name' => $this->playerName,
            'additional_data' => $this->additionalData,
        ];
    }
}


