<?php

namespace App\Events\Lobby;

use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento que se dispara cuando se actualiza la configuración de equipos
 */
class TeamsConfigUpdatedEvent implements ShouldBroadcast
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
        return 'teams.config.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => 'Configuración de equipos actualizada',
            'timestamp' => now()->toIso8601String()
        ];
    }
}
