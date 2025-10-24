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
 * Evento genérico para acciones de jugadores.
 *
 * Usado para notificar cuando un jugador hace una acción (responder, dibujar, etc.)
 */
class PlayerActionEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;
    public string $playerName;
    public string $actionType;
    public array $actionData;
    public bool $success;

    public function __construct(
        GameMatch $match,
        Player $player,
        string $actionType,
        array $actionData = [],
        bool $success = true
    ) {
        $this->roomCode = $match->room->code;
        $this->playerId = $player->id;
        $this->playerName = $player->name;
        $this->actionType = $actionType;
        $this->actionData = $actionData;
        $this->success = $success;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'game.player.action';
    }

    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'player_name' => $this->playerName,
            'action_type' => $this->actionType,
            'action_data' => $this->actionData,
            'success' => $this->success,
        ];
    }
}
