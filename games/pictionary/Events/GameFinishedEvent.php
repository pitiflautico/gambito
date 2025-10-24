<?php

namespace Games\Pictionary\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento que se dispara cuando termina el juego (todas las rondas completadas)
 */
class GameFinishedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public ?int $winnerId;
    public string $winnerName;
    public array $finalScores;
    public array $ranking;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $roomCode,
        ?int $winnerId,
        string $winnerName,
        array $finalScores,
        array $ranking
    ) {
        $this->roomCode = $roomCode;
        $this->winnerId = $winnerId;
        $this->winnerName = $winnerName;
        $this->finalScores = $finalScores;
        $this->ranking = $ranking;
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
        return '.pictionary.game.finished';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'winner_id' => $this->winnerId,
            'winner_name' => $this->winnerName,
            'final_scores' => $this->finalScores,
            'ranking' => $this->ranking,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
