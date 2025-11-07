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
        
        \Log::info('🎮 [GameStartedEvent] Evento creado', [
            'room_code' => $room->code,
            'room_id' => $room->id,
            'channel' => 'room.' . $room->code,
        ]);
    }

    public function broadcastOn(): array
    {
        $channel = new Channel('room.' . $this->room->code);
        
        \Log::info('🎮 [GameStartedEvent] Configurando canal de broadcast', [
            'room_code' => $this->room->code,
            'channel_name' => 'room.' . $this->room->code,
        ]);
        
        return [$channel];
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

        $data = [
            'room_code' => $this->room->code,
            'game_name' => $this->room->game->name,
            'players' => $players->toArray(),
            'total_players' => $players->count(),
            'timestamp' => now()->toIso8601String(),
        ];
        
        \Log::info('🎮 [GameStartedEvent] Datos del evento preparados', [
            'room_code' => $this->room->code,
            'total_players' => $players->count(),
            'event_name' => 'game.started',
        ]);

        return $data;
    }
}
