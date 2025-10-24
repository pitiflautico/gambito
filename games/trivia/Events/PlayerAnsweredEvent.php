<?php

namespace Games\Trivia\Events;

use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerAnsweredEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;
    public string $playerName;
    public int $answeredCount;
    public int $totalPlayers;
    public bool $isCorrect;

    /**
     * Create a new event instance.
     */
    public function __construct(
        GameMatch $match,
        Player $player,
        bool $isCorrect
    ) {
        $this->roomCode = $match->room->code;
        $this->playerId = $player->id;
        $this->playerName = $player->name;
        $this->isCorrect = $isCorrect;
        $this->answeredCount = count($match->game_state['player_answers'] ?? []);

        $roundManager = \App\Services\Modules\RoundSystem\RoundManager::fromArray($match->game_state);
        $this->totalPlayers = count($roundManager->getTurnOrder());
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
        return '.trivia.player.answered';
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
            'answered_count' => $this->answeredCount,
            'total_players' => $this->totalPlayers,
        ];
    }
}
