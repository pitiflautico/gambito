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

    public Room $room;

    public function __construct(Room $room)
    {
        $this->room = $room;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('room.' . $this->room->code),
        ];
    }

    public function broadcastAs(): string
    {
        return 'game.started';
    }

    public function broadcastWith(): array
    {
        $players = $this->room->match->players()
            ->where('is_connected', true)
            ->get(['id', 'name', 'user_id'])
            ->map(function ($player) {
                return [
                    'id' => $player->id,
                    'name' => $player->name,
                    'user_id' => $player->user_id,
                ];
            });

        return [
            'room_code' => $this->room->code,
            'game_name' => $this->room->game->name,
            'players' => $players->toArray(),
            'total_players' => $players->count(),
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
