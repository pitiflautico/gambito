<?php

namespace App\Events\Lobby;

use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerRemovedFromTeamEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;

    public function __construct(Room $room, int $playerId)
    {
        $this->roomCode = $room->code;
        $this->playerId = $playerId;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("lobby.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'player.removed';
    }

    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'timestamp' => now()->toIso8601String()
        ];
    }
}
