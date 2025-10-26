<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento genérico emitido cuando empieza una nueva ronda.
 *
 * Este evento es emitido por BaseGameEngine y puede ser usado por cualquier juego.
 * Cada juego puede escuchar este evento y ejecutar su lógica específica.
 *
 * Timing Metadata:
 * El campo `timing` contiene información para el TimingModule del frontend:
 * - duration (int): Duración de la ronda en segundos (si tiene límite de tiempo)
 * - countdown_visible (bool): Si debe mostrarse un countdown durante la ronda
 * - warning_threshold (int): Segundos para mostrar warning (cambio de color)
 */
class RoundStartedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $currentRound;
    public int $totalRounds;
    public string $phase;
    public array $gameState;
    public ?array $timing;

    /**
     * Create a new event instance.
     */
    public function __construct(
        GameMatch $match,
        int $currentRound,
        int $totalRounds,
        string $phase = 'playing',
        ?array $timing = null
    ) {
        $this->roomCode = $match->room->code;
        $this->currentRound = $currentRound;
        $this->totalRounds = $totalRounds;
        $this->phase = $phase;
        $this->gameState = $match->game_state;
        $this->timing = $timing;
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
        return 'game.round.started';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        $data = [
            'current_round' => $this->currentRound,
            'total_rounds' => $this->totalRounds,
            'phase' => $this->phase,
            'game_state' => $this->gameState,
        ];

        // Añadir timing metadata si está presente
        if ($this->timing !== null) {
            $data['timing'] = $this->timing;
        }

        return $data;
    }
}
