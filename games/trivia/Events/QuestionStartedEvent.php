<?php

namespace Games\Trivia\Events;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuestionStartedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public string $question;
    public array $options;
    public int $currentRound;
    public int $totalRounds;

    /**
     * Create a new event instance.
     */
    public function __construct(
        GameMatch $match,
        string $question,
        array $options,
        int $currentRound,
        int $totalRounds
    ) {
        $this->roomCode = $match->room->code;
        $this->question = $question;
        $this->options = $options;
        $this->currentRound = $currentRound;
        $this->totalRounds = $totalRounds;
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
        return 'trivia.question.started';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'question' => $this->question,
            'options' => $this->options,
            'current_round' => $this->currentRound,
            'total_rounds' => $this->totalRounds,
        ];
    }
}
