<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PhaseTimerExpiredEvent - Evento genérico cuando expira el timer de una fase SIN on_end configurado
 *
 * Este evento se emite AUTOMÁTICAMENTE cuando:
 * - El timer de una fase expira
 * - NO hay on_end configurado en config.json
 *
 * COMPORTAMIENTO AUTOMÁTICO:
 * - Si NO es la última fase → avanza a la siguiente fase
 * - Si ES la última fase → finaliza la ronda actual
 *
 * ESCALABILIDAD:
 * - Broadcast en canal específico por room (presence-room.{roomCode})
 * - Incluye match_id y room_code para correcta identificación
 * - 1000 partidas = 1000 canales separados sin interferencia
 */
class PhaseTimerExpiredEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $matchId;
    public string $phaseName;
    public array $phaseData;

    /**
     * Constructor del evento.
     *
     * @param GameMatch $match - Partida actual
     * @param array $phaseConfig - Configuración de la fase que expiró
     */
    public function __construct(GameMatch $match, array $phaseConfig = [])
    {
        $this->roomCode = $match->room->code;
        $this->matchId = $match->id;
        $this->phaseName = $phaseConfig['name'] ?? 'unknown';
        $this->phaseData = $phaseConfig;

        \Log::info("⏰ [PhaseTimerExpiredEvent] Timer expiró sin on_end configurado - Manejando automáticamente", [
            'phase' => $this->phaseName,
            'match_id' => $match->id
        ]);

        // Obtener PhaseManager
        $roundManager = $match->getEngine()->getRoundManager($match);
        $phaseManager = $roundManager->getTurnManager();

        if (!$phaseManager) {
            \Log::warning("[PhaseTimerExpiredEvent] PhaseManager no encontrado");
            return;
        }

        // Asignar match para que pueda emitir eventos
        $phaseManager->setMatch($match);

        // SIEMPRE avanzar a la siguiente fase
        \Log::info("➡️  [PhaseTimerExpiredEvent] Avanzando a siguiente fase", [
            'phase' => $this->phaseName,
            'match_id' => $match->id
        ]);

        $nextPhaseInfo = $phaseManager->nextPhase();

        // Guardar RoundManager actualizado
        $match->getEngine()->saveRoundManager($match, $roundManager);

        // Verificar si completó el ciclo de fases (cycle_completed = true)
        if ($nextPhaseInfo['cycle_completed']) {
            \Log::info("✅ [PhaseTimerExpiredEvent] Ciclo de fases completado - Finalizando ronda", [
                'phase' => $this->phaseName,
                'match_id' => $match->id,
                'next_phase' => $nextPhaseInfo['phase_name']
            ]);

            // Finalizar ronda actual
            // Esto emitirá RoundEndedEvent con countdown automático
            $match->getEngine()->endCurrentRound($match);
        } else {
            \Log::info("✅ [PhaseTimerExpiredEvent] Fase avanzada exitosamente", [
                'from' => $this->phaseName,
                'to' => $nextPhaseInfo['phase_name'],
                'cycle_completed' => false
            ]);

            // Emitir evento de cambio de fase
            event(new PhaseChangedEvent(
                match: $match,
                newPhase: $nextPhaseInfo['phase_name'],
                previousPhase: $this->phaseName,
                additionalData: [
                    'phase_index' => $nextPhaseInfo['phase_index'],
                    'duration' => $nextPhaseInfo['duration'] ?? null,
                    'phase_name' => $nextPhaseInfo['phase_name']
                ]
            ));
        }
    }

    /**
     * Canal de broadcast (room específico - ESCALABLE).
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
        return 'game.phase.timer.expired';
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
            'phase_data' => $this->phaseData,
        ];
    }
}
