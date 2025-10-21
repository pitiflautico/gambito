<?php

namespace Games\Pictionary\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento que se dispara cuando el drawer confirma si la respuesta es correcta o no
 */
class AnswerConfirmedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;
    public string $playerName;
    public bool $isCorrect;

    /**
     * Create a new event instance.
     */
    public function __construct(string $roomCode, int $playerId, string $playerName, bool $isCorrect)
    {
        $this->roomCode = $roomCode;
        $this->playerId = $playerId;
        $this->playerName = $playerName;
        $this->isCorrect = $isCorrect;
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
        return 'answer.confirmed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'player_name' => $this->playerName,
            'is_correct' => $this->isCorrect,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
