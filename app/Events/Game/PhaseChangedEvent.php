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
        $data = [
            'new_phase' => $this->newPhase,
            'previous_phase' => $this->previousPhase,
            'additional_data' => $this->additionalData,
        ];

        // CRÍTICO: TimingModule usa timer_name || phase || timer_id para determinar el nombre del timer
        // Necesitamos exponer 'phase' en el nivel raíz para que TimingModule use el nombre correcto de la fase
        $data['phase'] = $this->newPhase;
        
        // Si additional_data contiene timer_id, server_time, duration, extraerlos al nivel raíz
        // para que TimingModule los detecte automáticamente
        if (isset($this->additionalData['timer_id'])) {
            $data['timer_id'] = $this->additionalData['timer_id'];
        }
        if (isset($this->additionalData['server_time'])) {
            $data['server_time'] = $this->additionalData['server_time'];
        }
        if (isset($this->additionalData['duration'])) {
            $data['duration'] = $this->additionalData['duration'];
        }

        return $data;
    }
}
