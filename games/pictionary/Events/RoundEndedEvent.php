<?php

namespace Games\Pictionary\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento que se dispara cuando termina una ronda
 */
class RoundEndedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $round;
    public string $word;
    public ?int $winnerId; // Nullable cuando no hay ganador
    public string $winnerName;
    public int $guesserPoints;
    public int $drawerPoints;
    public array $scores;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $roomCode,
        int $round,
        string $word,
        ?int $winnerId, // Nullable cuando no hay ganador
        string $winnerName,
        int $guesserPoints,
        int $drawerPoints,
        array $scores
    ) {
        $this->roomCode = $roomCode;
        $this->round = $round;
        $this->word = $word;
        $this->winnerId = $winnerId;
        $this->winnerName = $winnerName;
        $this->guesserPoints = $guesserPoints;
        $this->drawerPoints = $drawerPoints;
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
        return 'pictionary.round.ended';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'round' => $this->round,
            'word' => $this->word,
            'winner_id' => $this->winnerId,
            'winner_name' => $this->winnerName,
            'guesser_points' => $this->guesserPoints,
            'drawer_points' => $this->drawerPoints,
            'scores' => $this->scores,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
