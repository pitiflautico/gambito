<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento genérico emitido cuando termina una ronda.
 */
class RoundEndedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $roundNumber;
    public array $results;
    public array $scores;

    public function __construct(
        GameMatch $match,
        int $roundNumber,
        array $results = [],
        array $scores = []
    ) {
        $this->roomCode = $match->room->code;
        $this->roundNumber = $roundNumber;
        $this->results = $results;
        $this->scores = $scores;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'game.round.ended';
    }

    public function broadcastWith(): array
    {
        return [
            'round_number' => $this->roundNumber,
            'results' => $this->results,
            'scores' => $this->scores,
        ];
    }
}
