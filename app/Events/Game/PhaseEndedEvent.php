<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PhaseEndedEvent - Evento genérico cuando termina cualquier fase
 *
 * Este evento es GENÉRICO y puede ser usado por cualquier juego.
 * Ejecuta el callback configurado en el engine si existe.
 *
 * CONFIGURACIÓN en config.json:
 * {
 *   "phases": [
 *     {
 *       "name": "phase1",
 *       "duration": 5,
 *       "on_end": "App\\Events\\Game\\PhaseEndedEvent",
 *       "on_end_callback": "handlePhase1Ended"
 *     }
 *   ]
 * }
 */
class PhaseEndedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $matchId;
    public string $phaseName;
    public string $completedAt;
    public array $phaseData;

    /**
     * Constructor del evento.
     *
     * @param GameMatch $match - Partida actual
     * @param array $phaseConfig - Configuración de la fase que terminó
     */
    public function __construct(GameMatch $match, array $phaseConfig = [])
    {
        $this->roomCode = $match->room->code;
        $this->matchId = $match->id;
        $this->phaseName = $phaseConfig['name'] ?? 'unknown';
        $this->completedAt = now()->toDateTimeString();
        $this->phaseData = $phaseConfig;

        $engine = $match->getEngine();

        if (!$engine) {
            \Log::error("[PhaseEndedEvent] Engine not found", [
                'phase' => $this->phaseName,
                'match_id' => $match->id
            ]);
            return;
        }

        // PRIORIDAD 1: Si hay callback configurado → ejecutarlo (control manual)
        $callback = $phaseConfig['on_end_callback'] ?? null;

        if ($callback && method_exists($engine, $callback)) {
            \Log::info("🎯 [PhaseEndedEvent] Calling custom callback", [
                'phase' => $this->phaseName,
                'callback' => $callback,
                'match_id' => $match->id,
                'engine_class' => get_class($engine)
            ]);

            // El callback es responsable de manejar el avance
            $engine->$callback($match, $phaseConfig);
        } else {
            // PRIORIDAD 2: Sin callback → auto-advance vía handlePhaseEnded()
            \Log::info("🔄 [PhaseEndedEvent] No callback - using auto-advance", [
                'phase' => $this->phaseName,
                'match_id' => $match->id
            ]);

            $engine->handlePhaseEnded($match, $phaseConfig);
        }
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
        return 'game.phase.ended';
    }

    /**
     * Datos que se envían al frontend.
     */
    public function broadcastWith(): array
    {
        return [
            'match_id' => $this->matchId,
            'room_code' => $this->roomCode,
            'phase_name' => $this->phaseName,
            'completed_at' => $this->completedAt,
            'phase_data' => $this->phaseData,
        ];
    }
}
