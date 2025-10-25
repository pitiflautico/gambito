<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento: Engine del juego inicializado
 *
 * Se emite cuando el engine del juego ha sido cargado
 * y está listo para comenzar a jugar.
 */
class GameInitializedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public GameMatch $match,
        public array $initialState = []
    ) {}

    /**
     * Nombre del evento en el cliente
     */
    public function broadcastAs(): string
    {
        return 'game.initialized';
    }

    /**
     * Datos que se envían al cliente
     */
    public function broadcastWith(): array
    {
        return [
            'room_code' => $this->match->room->code,
            'game' => $this->match->room->game->slug,
            'phase' => 'playing',
            'initial_state' => $this->initialState,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Canal donde se broadcastea
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('room.' . $this->match->room->code),
        ];
    }
}
