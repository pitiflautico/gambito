<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento genérico emitido cuando termina una ronda.
 *
 * Timing Metadata:
 * El campo `timing` contiene información para el TimingModule del frontend:
 * - auto_next (bool): Si debe avanzar automáticamente a la siguiente ronda
 * - delay (int): Segundos a esperar antes de avanzar
 * - action (string): Acción a realizar (ej: 'next_round', 'show_results')
 * - message (string): Mensaje para mostrar en countdown (opcional)
 */
class RoundEndedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public GameMatch $match;
    public string $roomCode;
    public int $roundNumber;
    public array $results;
    public array $scores;
    public ?array $timing;
    public bool $isLastRound;

    public function __construct(
        GameMatch $match,
        int $roundNumber,
        array $results = [],
        array $scores = [],
        ?array $timing = null,
        bool $isLastRound = false
    ) {
        $this->match = $match;
        $this->roomCode = $match->room->code;
        $this->roundNumber = $roundNumber;
        $this->results = $results;
        $this->scores = $scores;
        $this->timing = $timing;
        $this->isLastRound = $isLastRound;
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'game.round.ended';
    }

    public function broadcastWith(): array
    {
        $data = [
            'round_number' => $this->roundNumber,
            'results' => $this->results,
            'scores' => $this->scores,
            'is_last_round' => $this->isLastRound,
        ];

        // Añadir timing metadata si está presente
        if ($this->timing !== null) {
            $data['timing'] = $this->timing;

            // Si el timing es de tipo countdown, incluir datos para TimingModule
            // PERO solo si NO es la última ronda (en la última ronda no hay countdown)
            if (!$this->isLastRound &&
                ($this->timing['type'] ?? null) === 'countdown' &&
                ($this->timing['auto_next'] ?? false) === true) {

                $data['timer_id'] = 'timer'; // Reutilizar mismo elemento que fases
                $data['timer_name'] = 'round_ended_countdown_' . $this->roundNumber;
                $data['server_time'] = now()->timestamp; // Timestamp actual para sincronización
                $data['duration'] = $this->timing['delay'] ?? 3;
                $data['event_class'] = \App\Events\Game\StartNewRoundEvent::class;

                // Incluir los datos que StartNewRoundEvent necesita
                $data['event_data'] = [
                    'matchId' => $this->match->id,
                    'roomCode' => $this->roomCode
                ];
            }
        }

        return $data;
    }
}
