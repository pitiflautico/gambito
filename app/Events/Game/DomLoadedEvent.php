<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento: DOM Loaded
 *
 * Se emite cuando el frontend (DOM) de un jugador está completamente cargado
 * y listo para recibir eventos. El backend usa este evento para coordinar el
 * inicio del juego cuando TODOS los jugadores tienen su DOM cargado.
 *
 * Flujo:
 * 1. Jugador carga /play
 * 2. BaseGameClient emite DomLoadedEvent vía API
 * 3. Backend cuenta jugadores con DOM cargado
 * 4. Cuando todos están listos → Backend emite GameStartedEvent
 * 5. Todos los frontends reciben GameStartedEvent al mismo tiempo
 */
class DomLoadedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;
    public int $totalPlayers;
    public int $playersReady;

    /**
     * Create a new event instance.
     */
    public function __construct(string $roomCode, int $playerId, int $totalPlayers, int $playersReady)
    {
        $this->roomCode = $roomCode;
        $this->playerId = $playerId;
        $this->totalPlayers = $totalPlayers;
        $this->playersReady = $playersReady;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("room.{$this->roomCode}");
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'dom.loaded';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'total_players' => $this->totalPlayers,
            'players_ready' => $this->playersReady,
            'all_ready' => $this->playersReady >= $this->totalPlayers,
        ];
    }
}
