<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event emitido cuando expira el timer de una fase en PhaseManager.
 *
 * Este evento permite a los juegos reaccionar al timer expirado:
 * - Avanzar a la siguiente fase
 * - Terminar la ronda
 * - Realizar lógica específica del juego
 *
 * Uso:
 * ```php
 * Event::listen(PhaseTimerExpiredEvent::class, function($event) {
 *     $match = $event->match;
 *     $phaseName = $event->phaseName;
 *     // Lógica del juego
 * });
 * ```
 */
class PhaseTimerExpiredEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * ID de la partida
     */
    public ?int $matchId;

    /**
     * Nombre de la fase que expiró
     */
    public string $phaseName;

    /**
     * Índice de la fase (0-based)
     */
    public int $phaseIndex;

    /**
     * Si es la última fase del ciclo
     */
    public bool $isLastPhase;

    /**
     * Constructor.
     *
     * @param int|null $matchId
     * @param string $phaseName
     * @param int $phaseIndex
     * @param bool $isLastPhase
     */
    public function __construct(
        ?int $matchId,
        string $phaseName,
        int $phaseIndex = 0,
        bool $isLastPhase = false
    ) {
        $this->matchId = $matchId;
        $this->phaseName = $phaseName;
        $this->phaseIndex = $phaseIndex;
        $this->isLastPhase = $isLastPhase;
    }
}
