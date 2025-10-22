<?php

namespace Games\Trivia\Events;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameFinishedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public array $ranking;
    public array $statistics;

    /**
     * Create a new event instance.
     */
    public function __construct(
        GameMatch $match,
        array $ranking,
        array $statistics
    ) {
        $this->roomCode = $match->room->code;
        $this->ranking = $ranking;
        $this->statistics = $statistics;
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
        return 'trivia.game.finished';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'ranking' => $this->ranking,
            'statistics' => $this->statistics,
        ];
    }
}
