<?php

namespace Games\Pictionary\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento que se dispara cuando cambia el turno (nuevo drawer)
 */
class TurnChangedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $newDrawerId;
    public string $newDrawerName;
    public int $round;
    public int $turn;
    public array $scores;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $roomCode,
        int $newDrawerId,
        string $newDrawerName,
        int $round,
        int $turn,
        array $scores
    ) {
        $this->roomCode = $roomCode;
        $this->newDrawerId = $newDrawerId;
        $this->newDrawerName = $newDrawerName;
        $this->round = $round;
        $this->turn = $turn;
        $this->scores = $scores;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('room.' . $this->roomCode),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'pictionary.turn.changed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'new_drawer_id' => $this->newDrawerId,
            'new_drawer_name' => $this->newDrawerName,
            'round' => $this->round,
            'turn' => $this->turn,
            'scores' => $this->scores,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
