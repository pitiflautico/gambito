<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento: Todos los jugadores están conectados al Presence Channel
 *
 * Se dispara desde el backend cuando detecta que todos los players
 * de una sala están conectados al WebSocket.
 */
class AllPlayersConnectedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Room $room,
        public int $connectedCount,
        public int $totalPlayers
    ) {}

    /**
     * Nombre del evento en el cliente
     */
    public function broadcastAs(): string
    {
        return 'players.all-connected';
    }

    /**
     * Datos que se envían al cliente
     */
    public function broadcastWith(): array
    {
        return [
            'room_code' => $this->room->code,
            'connected' => $this->connectedCount,
            'total' => $this->totalPlayers,
            'message' => '¡Todos los jugadores están conectados!',
        ];
    }

    /**
     * Canal donde se broadcastea (sala específica)
     * Se emite en el canal normal de la sala, NO en el Presence Channel
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('room.' . $this->room->code),
        ];
    }
}
