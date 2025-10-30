<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PhaseStartedEvent - Evento genérico cuando inicia cualquier fase
 *
 * Este evento es GENÉRICO y puede ser usado por cualquier juego.
 * Ejecuta el callback configurado en el engine si existe.
 *
 * ESCALABILIDAD:
 * - Broadcast en canal específico por room (presence-room.{roomCode})
 * - Incluye match_id y room_code para correcta identificación
 * - 1000 partidas = 1000 canales separados sin interferencia
 *
 * CONFIGURACIÓN en config.json:
 * {
 *   "phases": [
 *     {
 *       "name": "phase2",
 *       "duration": 5,
 *       "on_start": "App\\Events\\Game\\PhaseStartedEvent",
 *       "on_start_callback": "handlePhase2Started"
 *     }
 *   ]
 * }
 */
class PhaseStartedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $matchId;
    public string $phaseName;
    public int $duration;
    public string $startedAt;
    public array $phaseData;

    /**
     * Constructor del evento.
     *
     * @param GameMatch $match - Partida actual
     * @param array $phaseConfig - Configuración de la fase que inició
     */
    public function __construct(GameMatch $match, array $phaseConfig = [])
    {
        $this->roomCode = $match->room->code;
        $this->matchId = $match->id;
        $this->phaseName = $phaseConfig['name'] ?? 'unknown';
        $this->duration = $phaseConfig['duration'] ?? 0;
        $this->startedAt = now()->toDateTimeString();
        $this->phaseData = $phaseConfig;

        // Ejecutar callback si está configurado
        $callback = $phaseConfig['on_start_callback'] ?? null;

        if ($callback) {
            $engine = $match->getEngine();

            if ($engine && method_exists($engine, $callback)) {
                \Log::info("🎯 [PhaseStartedEvent] Ejecutando callback del engine", [
                    'phase' => $this->phaseName,
                    'callback' => $callback,
                    'match_id' => $match->id,
                    'engine_class' => get_class($engine)
                ]);

                // Llamar al callback del engine
                $engine->$callback($match, $phaseConfig);
            } else {
                \Log::warning("[PhaseStartedEvent] Callback no encontrado en el engine", [
                    'phase' => $this->phaseName,
                    'callback' => $callback,
                    'engine_class' => get_class($engine ?? null)
                ]);
            }
        } else {
            \Log::info("[PhaseStartedEvent] Fase sin callback configurado", [
                'phase' => $this->phaseName
            ]);
        }
    }

    /**
     * Canal de broadcast (room específico - ESCALABLE).
     * Cada room tiene su propio canal aislado.
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
        \Log::debug("[PhaseStartedEvent] Broadcasting", [
            'room' => $this->roomCode,
            'phase_name' => $this->phaseName,
            'channel' => 'presence-room.' . $this->roomCode,
            'event_name' => 'game.phase.started'
        ]);

        return 'game.phase.started';
    }

    /**
     * Datos que se envían al frontend.
     * IMPORTANTE: Incluir room_code y match_id para correcta identificación.
     * Incluir timer_id, server_time, duration para que TimingModule lo detecte automáticamente.
     */
    public function broadcastWith(): array
    {
        return [
            'match_id' => $this->matchId,
            'room_code' => $this->roomCode,
            'phase_name' => $this->phaseName,
            'duration' => $this->duration,
            'started_at' => $this->startedAt,
            'phase_data' => $this->phaseData,
            // Datos necesarios para TimingModule
            'timer_id' => 'timer', // ID del elemento HTML donde mostrar el timer
            'timer_name' => $this->phaseName,
            'server_time' => now()->timestamp, // Para sincronización de timers
            // Evento a emitir cuando expire (para que el frontend lo reenvíe)
            // Si no hay on_end configurado, usar PhaseTimerExpiredEvent por defecto (avance automático)
            'event_class' => $this->phaseData['on_end'] ?? 'App\\Events\\Game\\PhaseTimerExpiredEvent',
        ];
    }
}
