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
 * Evento emitido cuando un jugador se reconecta DURANTE la partida.
 *
 * Este evento indica que el jugador volvió y el juego puede continuar.
 */
class PlayerReconnectedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;
    public string $playerName;
    public string $gamePhase;
    public ?int $currentRound;
    public bool $shouldRestartRound;

    public function __construct(
        GameMatch $match,
        Player $player,
        bool $shouldRestartRound = true
    ) {
        $this->roomCode = $match->room->code;
        $this->playerId = $player->id;
        $this->playerName = $player->name;
        $this->gamePhase = $match->game_state['phase'] ?? 'unknown';
        $this->currentRound = $match->game_state['round_system']['current_round'] ?? null;
        $this->shouldRestartRound = $shouldRestartRound;
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'game.player.reconnected';
    }

    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'player_name' => $this->playerName,
            'game_phase' => $this->gamePhase,
            'current_round' => $this->currentRound,
            'should_restart_round' => $this->shouldRestartRound,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
