<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento emitido cuando un jugador se desconecta DURANTE la partida.
 *
 * Esto es diferente a desconectarse en el lobby.
 * Durante la partida, la desconexión puede pausar el juego.
 */
class PlayerDisconnectedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;
    public string $playerName;
    public string $gamePhase;
    public ?int $currentRound;

    public function __construct(
        GameMatch $match,
        Player $player
    ) {
        $this->roomCode = $match->room->code;
        $this->playerId = $player->id;
        $this->playerName = $player->name;
        $this->gamePhase = $match->game_state['phase'] ?? 'unknown';
        $this->currentRound = $match->game_state['round_system']['current_round'] ?? null;
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'game.player.disconnected';
    }

    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'player_name' => $this->playerName,
            'game_phase' => $this->gamePhase,
            'current_round' => $this->currentRound,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
