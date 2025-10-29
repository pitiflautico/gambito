<?php

namespace App\Events\Mockup;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
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
class Phase1EndedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public GameMatch $match;
    public array $phaseData;

    /**
     * Constructor del evento.
     *
     * @param GameMatch $match - Partida actual
     * @param array $phaseData - Datos adicionales de la fase (opcional)
     */
    public function __construct(GameMatch $match, array $phaseData = [])
    {
        $this->match = $match;
        $this->phaseData = $phaseData;
    }

    /**
     * Canal de broadcast (room específico).
     */
    public function broadcastOn(): Channel
    {
        return new Channel('room.' . $this->match->room->code);
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
            'match_id' => $this->match->id,
            'room_code' => $this->match->room->code,
            'phase' => 'phase1',
            'completed_at' => now()->toDateTimeString(),
            'phase_data' => $this->phaseData,
        ];
    }
}
