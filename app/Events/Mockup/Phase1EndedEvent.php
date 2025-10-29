<?php

namespace App\Events\Mockup;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase1EndedEvent - Evento custom para cuando termina la Fase 1 de Mockup
 *
 * Este evento es un EJEMPLO de cómo los juegos pueden tener eventos personalizados
 * para lógica específica de negocio. PhaseManager lo instancia y emite cuando:
 * - El timer de phase1 expira
 * - config.json define: phases[0].custom_event = "App\\Events\\Mockup\\Phase1EndedEvent"
 *
 * USO:
 * - PhaseManager hace: new Phase1EndedEvent($match, $phaseData)
 * - Se broadcast al frontend automáticamente
 * - Frontend puede suscribirse para lógica específica de fase 1
 */
class Phase1EndedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $matchId;
    public string $phase;
    public string $completedAt;
    public array $phaseData;

    /**
     * Constructor del evento.
     *
     * @param GameMatch $match - Partida actual
     * @param array $phaseData - Datos adicionales de la fase (opcional)
     */
    public function __construct(GameMatch $match, array $phaseData = [])
    {
        $this->roomCode = $match->room->code;
        $this->matchId = $match->id;
        $this->phase = 'phase1';
        $this->completedAt = now()->toDateTimeString();
        $this->phaseData = $phaseData;
    }

    /**
     * Canal de broadcast (room específico).
     */
    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('room.' . $this->roomCode);
    }

    /**
     * Nombre del evento para el frontend.
     */
    public function broadcastAs(): string
    {
        return 'mockup.phase1.ended';
    }

    /**
     * Datos que se envían al frontend.
     */
    public function broadcastWith(): array
    {
        return [
            'match_id' => $this->matchId,
            'room_code' => $this->roomCode,
            'phase' => $this->phase,
            'completed_at' => $this->completedAt,
            'phase_data' => $this->phaseData,
        ];
    }
}
