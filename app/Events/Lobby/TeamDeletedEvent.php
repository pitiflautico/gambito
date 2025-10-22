<?php

namespace App\Events\Lobby;

use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TeamDeletedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public string $teamId;

    public function __construct(Room $room, string $teamId)
    {
        $this->roomCode = $room->code;
        $this->teamId = $teamId;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("lobby.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'team.deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'team_id' => $this->teamId,
            'timestamp' => now()->toIso8601String()
        ];
    }
}
