<?php

namespace Games\Trivia\Events;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuestionEndedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $correctAnswer;
    public array $results;
    public array $scores;

    /**
     * Create a new event instance.
     */
    public function __construct(
        GameMatch $match,
        int $correctAnswer,
        array $results,
        array $scores
    ) {
        $this->roomCode = $match->room->code;
        $this->correctAnswer = $correctAnswer;
        $this->results = $results;
        $this->scores = $scores;
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
        return 'trivia.question.ended';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'correct_answer' => $this->correctAnswer,
            'results' => $this->results,
            'scores' => $this->scores,
        ];
    }
}
