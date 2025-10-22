<?php

namespace App\Events\Lobby;

use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TeamCreatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public array $team;

    public function __construct(Room $room, array $team)
    {
        $this->roomCode = $room->code;
        $this->team = $team;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("lobby.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'team.created';
    }

    public function broadcastWith(): array
    {
        return [
            'team' => $this->team,
            'timestamp' => now()->toIso8601String()
        ];
    }
}
