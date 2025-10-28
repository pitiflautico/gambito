<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento genérico emitido cuando cambia la fase del juego.
 *
 * Fases comunes: waiting, playing, scoring, results, finished
 */
class PhaseChangedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public string $newPhase;
    public string $previousPhase;
    public array $additionalData;

    public function __construct(
        GameMatch $match,
        string $newPhase,
        string $previousPhase = '',
        array $additionalData = []
    ) {
        $this->roomCode = $match->room->code;
        $this->newPhase = $newPhase;
        $this->previousPhase = $previousPhase;
        $this->additionalData = $additionalData;

        \Log::debug("[PhaseChangedEvent] Broadcasting", [
            'room' => $this->roomCode,
            'new_phase' => $this->newPhase,
            'previous_phase' => $this->previousPhase,
            'additional_data' => $this->additionalData,
            'channel' => "presence-room.{$this->roomCode}",
            'event_name' => 'game.phase.changed'
        ]);
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'game.phase.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'new_phase' => $this->newPhase,
            'previous_phase' => $this->previousPhase,
            'additional_data' => $this->additionalData,
        ];
    }
}
