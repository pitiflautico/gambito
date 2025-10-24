<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento genérico emitido cuando empieza una nueva ronda.
 *
 * Este evento es emitido por BaseGameEngine y puede ser usado por cualquier juego.
 * Cada juego puede escuchar este evento y ejecutar su lógica específica.
 */
class RoundStartedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $currentRound;
    public int $totalRounds;
    public string $phase;
    public array $gameState;

    /**
     * Create a new event instance.
     */
    public function __construct(
        GameMatch $match,
        int $currentRound,
        int $totalRounds,
        string $phase = 'playing'
    ) {
        $this->roomCode = $match->room->code;
        $this->currentRound = $currentRound;
        $this->totalRounds = $totalRounds;
        $this->phase = $phase;
        $this->gameState = $match->game_state;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->roomCode}");
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'game.round.started';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'current_round' => $this->currentRound,
            'total_rounds' => $this->totalRounds,
            'phase' => $this->phase,
            'game_state' => $this->gameState,
        ];
    }
}
