<?php

namespace App\Events\Game;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento emitido cuando un jugador se conecta a la sala del juego.
 *
 * Se usa para mostrar en tiempo real el progreso de conexiones:
 * "Esperando jugadores... (2/3)"
 */
class PlayerConnectedToGameEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $connectedCount;
    public int $totalPlayers;

    public function __construct(string $roomCode, int $connectedCount, int $totalPlayers)
    {
        $this->roomCode = $roomCode;
        $this->connectedCount = $connectedCount;
        $this->totalPlayers = $totalPlayers;

        \Log::info('📱 [PlayerConnectedToGameEvent] Player connected', [
            'room_code' => $roomCode,
            'connected' => $connectedCount,
            'total' => $totalPlayers
        ]);
    }

    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'player.connected';
    }

    public function broadcastWith(): array
    {
        return [
            'connected_count' => $this->connectedCount,
            'total_players' => $this->totalPlayers,
        ];
    }
}
