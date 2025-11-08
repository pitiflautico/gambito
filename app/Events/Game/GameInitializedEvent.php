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
    ) {
        \Log::info('🎮 [GameInitializedEvent] Evento creado', [
            'room_code' => $match->room->code,
            'room_id' => $match->room->id,
            'match_id' => $match->id,
            'channel' => 'room.' . $match->room->code,
            'phase' => $initialState['phase'] ?? 'unknown',
        ]);
    }

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
        $channel = new Channel('room.' . $this->match->room->code);
        \Log::info('🎮 [GameInitializedEvent] Configurando canal de broadcast', [
            'room_code' => $this->match->room->code,
            'channel_name' => 'room.' . $this->match->room->code,
            'event_name' => 'game.initialized',
        ]);
        return [$channel];
    }
}
