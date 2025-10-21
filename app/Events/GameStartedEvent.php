<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameStartedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public string $gameName;
    public int $totalPlayers;

    public function __construct(Room $room, int $totalPlayers)
    {
        $this->roomCode = $room->code;
        $this->gameName = $room->game->name;
        $this->totalPlayers = $totalPlayers;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('room.' . $this->roomCode),
        ];
    }

    public function broadcastAs(): string
    {
        return 'game.started';
    }

    public function broadcastWith(): array
    {
        return [
            'game_name' => $this->gameName,
            'total_players' => $this->totalPlayers,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
