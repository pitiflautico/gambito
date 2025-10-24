<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento genérico emitido cuando cambia el turno.
 */
class TurnChangedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $currentPlayerId;
    public string $currentPlayerName;
    public int $currentRound;
    public int $turnIndex;
    public bool $cycleCompleted;
    public array $playerRoles;

    public function __construct(
        GameMatch $match,
        int $currentPlayerId,
        string $currentPlayerName,
        int $currentRound,
        int $turnIndex,
        bool $cycleCompleted = false,
        array $playerRoles = []
    ) {
        $this->roomCode = $match->room->code;
        $this->currentPlayerId = $currentPlayerId;
        $this->currentPlayerName = $currentPlayerName;
        $this->currentRound = $currentRound;
        $this->turnIndex = $turnIndex;
        $this->cycleCompleted = $cycleCompleted;
        $this->playerRoles = $playerRoles;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'game.turn.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'current_player_id' => $this->currentPlayerId,
            'current_player_name' => $this->currentPlayerName,
            'current_round' => $this->currentRound,
            'turn_index' => $this->turnIndex,
            'cycle_completed' => $this->cycleCompleted,
            'player_roles' => $this->playerRoles,
        ];
    }
}
