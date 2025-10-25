<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento emitido cuando el juego termina completamente.
 */
class GameEndedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public ?int $winner;
    public array $ranking;
    public array $scores;

    public function __construct(
        GameMatch $match,
        ?int $winner,
        array $ranking,
        array $scores
    ) {
        $this->roomCode = $match->room->code;
        $this->winner = $winner;
        $this->ranking = $ranking;
        $this->scores = $scores;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'game.ended';
    }

    public function broadcastWith(): array
    {
        return [
            'winner' => $this->winner,
            'ranking' => $this->ranking,
            'scores' => $this->scores,
        ];
    }
}

