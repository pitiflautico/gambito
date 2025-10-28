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

    public function __construct(
        GameMatch $match,
        int $roundNumber,
        array $results = [],
        array $scores = [],
        ?array $timing = null
    ) {
        $this->match = $match;
        $this->roomCode = $match->room->code;
        $this->roundNumber = $roundNumber;
        $this->results = $results;
        $this->scores = $scores;
        $this->timing = $timing;
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
        ];

        // Añadir timing metadata si está presente
        if ($this->timing !== null) {
            $data['timing'] = $this->timing;
        }

        return $data;
    }
}
