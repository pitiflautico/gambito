<?php

namespace App\Events\Mockup;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento emitido cuando inicia la Fase 2 en MockupGame.
 *
 * Este evento se emite ANTES de iniciar el timer de la fase,
 * permitiendo al juego preparar datos, mostrar botones, etc.
 *
 * EJEMPLO DE EVENTO CUSTOM ESPECÍFICO (Opción B)
 */
class Phase2StartedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $matchId;
    public string $phase;
    public ?int $duration;
    public string $timerId;
    public int $serverTime;
    public array $phaseData;

    /**
     * Create a new event instance.
     */
    public function __construct(GameMatch $match, array $phaseConfig)
    {
        $this->roomCode = $match->room->code;
        $this->matchId = $match->id;
        $this->phase = 'phase2';
        $this->duration = $phaseConfig['duration'] ?? null;
        $this->timerId = 'timer'; // ID del elemento HTML donde se muestra el countdown
        $this->serverTime = now()->timestamp; // Timestamp del servidor para sincronización
        $this->phaseData = $phaseConfig;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('room.' . $this->roomCode);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        \Log::debug("[Phase2StartedEvent] Broadcasting", [
            'room' => $this->roomCode,
            'phase' => $this->phase,
            'channel' => 'presence-room.' . $this->roomCode,
            'event_name' => 'mockup.phase2.started'
        ]);

        return 'mockup.phase2.started';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'room_code' => $this->roomCode,
            'match_id' => $this->matchId,
            'phase' => $this->phase,
            'phase_name' => $this->phase,
            'duration' => $this->duration,
            'timer_id' => $this->timerId,
            'timer_name' => $this->phase, // Para que el frontend sepa qué timer es
            'server_time' => $this->serverTime,
            'phase_data' => $this->phaseData,
            // Evento a emitir cuando expire (para que el frontend lo reenvíe)
            // Si no hay on_end configurado, usar PhaseTimerExpiredEvent por defecto (avance automático)
            'event_class' => $this->phaseData['on_end'] ?? 'App\\Events\\Game\\PhaseTimerExpiredEvent',
        ];
    }
}
