<?php

namespace App\Events\Lobby;

use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TeamsBalancedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;

    public function __construct(Room $room)
    {
        $this->roomCode = $room->code;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("lobby.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'teams.balanced';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => 'Equipos balanceados automáticamente',
            'timestamp' => now()->toIso8601String()
        ];
    }
}
