<?php

namespace App\Services\Modules\TurnSystem;

/**
 * Phase Manager - Extensión de TurnManager para manejar fases dentro de una ronda.
 *
 * Permite que cada fase tenga su propia duración de timer.
 * Útil para juegos como Mentiroso que tienen múltiples fases en una ronda:
 * - preparation (15s)
 * - persuasion (30s)
 * - voting (10s)
 *
 * Uso:
 * ```php
 * $phaseManager = new PhaseManager([
 *     ['name' => 'preparation', 'duration' => 15],
 *     ['name' => 'persuasion', 'duration' => 30],
 *     ['name' => 'voting', 'duration' => 10]
 * ]);
 * ```
 */
class PhaseManager extends TurnManager
{
    /**
     * Configuración de fases con sus duraciones.
     * @var array<array{name: string, duration: int}>
     */
    protected array $phases;

    /**
     * Callbacks a ejecutar cuando expira el timer de cada fase.
     * @var array<string, callable>
     */
    protected array $phaseCallbacks = [];

    /**
     * Constructor.
     *
     * @param array $phases Array de fases: [['name' => 'preparation', 'duration' => 15], ...]
     */
    public function __construct(array $phases)
    {
        if (empty($phases)) {
            throw new \InvalidArgumentException('Se requiere al menos una fase');
        }

        $this->phases = $phases;

        // Extraer nombres de fases para el TurnManager
        $phaseNames = array_map(fn($p) => $p['name'], $phases);

        // Llamar constructor del padre con la duración de la primera fase
        parent::__construct(
            $phaseNames,
            'sequential',
            $phases[0]['duration'] ?? null
        );
    }

    /**
     * Avanzar a la siguiente fase.
     *
     * Override de nextTurn() para actualizar el timeLimit de la nueva fase.
     *
     * @return array ['phase_name', 'phase_index', 'cycle_completed', 'duration']
     */
    public function nextPhase(): array
    {
        // Avanzar al siguiente "turno" (fase)
        $turnInfo = parent::nextTurn();

        // Actualizar timeLimit para la nueva fase
        $currentPhaseConfig = $this->phases[$this->currentTurnIndex];
        $this->timeLimit = $currentPhaseConfig['duration'] ?? null;

        // Iniciar timer de la nueva fase
        $this->startTurnTimer();

        return [
            'phase_name' => $currentPhaseConfig['name'],
            'phase_index' => $this->currentTurnIndex,
            'cycle_completed' => $turnInfo['cycle_completed'],
            'duration' => $this->timeLimit,
        ];
    }

    /**
     * Override: Iniciar timer con configuración de evento.
     *
     * PhaseManager sobrescribe startTurnTimer() para configurar el timer
     * con el evento PhaseTimerExpiredEvent que se emitirá al expirar.
     *
     * @return bool True si se inició el timer
     */
    public function startTurnTimer(): bool
    {
        if ($this->timeLimit === null || $this->timerService === null) {
            return false;
        }

        $phaseName = $this->getCurrentPhaseName();

        // Cancelar timer anterior si existe
        if ($this->timerService->hasTimer($phaseName)) {
            $this->timerService->cancelTimer($phaseName);
        }

        // Preparar datos del evento
        // NOTA: NO podemos pasar el $match aquí porque no se serializa correctamente
        // En su lugar, pasamos solo el match_id y el listener lo cargará
        $eventData = [
            'matchId' => $this->match ? $this->match->id : null,
            'phaseName' => $phaseName,
            'phaseIndex' => $this->currentTurnIndex,
            'isLastPhase' => $this->isLastPhase()
        ];

        // Crear timer con evento configurado
        $this->timerService->startTimer(
            timerName: $phaseName,
            durationSeconds: $this->timeLimit,
            eventToEmit: \App\Events\Game\PhaseTimerExpiredEvent::class,
            eventData: $eventData
        );

        return true;
    }

    /**
     * Obtener el nombre de la fase actual.
     *
     * @return string
     */
    public function getCurrentPhaseName(): string
    {
        return $this->phases[$this->currentTurnIndex]['name'];
    }

    /**
     * Obtener la configuración de la fase actual.
     *
     * @return array{name: string, duration: int}
     */
    public function getCurrentPhase(): array
    {
        return $this->phases[$this->currentTurnIndex];
    }

    /**
     * Obtener todas las fases configuradas.
     *
     * @return array
     */
    public function getPhases(): array
    {
        return $this->phases;
    }

    /**
     * Verificar si es la última fase del ciclo.
     *
     * @return bool
     */
    public function isLastPhase(): bool
    {
        return $this->currentTurnIndex === count($this->phases) - 1;
    }

    /**
     * GameMatch asociado (necesario para emitir eventos)
     */
    protected ?\App\Models\GameMatch $match = null;

    /**
     * Establecer el GameMatch asociado.
     *
     * @param \App\Models\GameMatch $match
     */
    public function setMatch(\App\Models\GameMatch $match): void
    {
        $this->match = $match;
    }

    /**
     * Registrar callback para cuando expira el timer de una fase.
     *
     * @param string $phaseName Nombre de la fase
     * @param callable $callback Función a ejecutar
     */
    public function onPhaseExpired(string $phaseName, callable $callback): void
    {
        $this->phaseCallbacks[$phaseName] = $callback;
    }

    /**
     * Ejecutar callback de expiración si existe y emitir evento.
     *
     * Este método debe ser llamado cuando expira el timer de una fase.
     * Emite el evento PhaseTimerExpiredEvent para que los juegos puedan reaccionar.
     *
     * @return bool True si se ejecutó callback o emitió evento, false si no hay nada
     */
    public function triggerPhaseExpired(): bool
    {
        $currentPhaseName = $this->getCurrentPhaseName();
        $hasCallback = isset($this->phaseCallbacks[$currentPhaseName]);

        // Emitir evento si tenemos el match
        if ($this->match !== null) {
            event(new \App\Events\Game\PhaseTimerExpiredEvent(
                $this->match,
                $currentPhaseName,
                $this->currentTurnIndex,
                $this->isLastPhase()
            ));
        }

        // Ejecutar callback si existe (backward compatibility)
        if ($hasCallback) {
            $callback = $this->phaseCallbacks[$currentPhaseName];
            $callback($this->getCurrentPhase());
            return true;
        }

        // Retornar true si al menos emitimos el evento
        return $this->match !== null;
    }

    /**
     * Obtener información de timing para el frontend.
     *
     * @return array|null
     */
    public function getTimingInfo(): ?array
    {
        $baseInfo = parent::getTimingInfo();

        if ($baseInfo === null) {
            return null;
        }

        $currentPhase = $this->getCurrentPhase();

        return array_merge($baseInfo, [
            'phase_name' => $currentPhase['name'],
            'phase_index' => $this->currentTurnIndex,
            'is_last_phase' => $this->isLastPhase(),
        ]);
    }

    /**
     * Reset a la primera fase.
     */
    public function reset(): void
    {
        parent::reset();

        // Actualizar timeLimit a la primera fase
        $this->timeLimit = $this->phases[0]['duration'] ?? null;
    }

    /**
     * Cancelar todos los timers de las fases.
     *
     * Este método se llama cuando la ronda termina prematuramente (ej: todos votaron).
     * PhaseManager cancela sus propios timers sin que nadie le diga cuáles son.
     */
    public function cancelAllTimers(): void
    {
        if (!$this->timerService) {
            return;
        }

        // Iterar sobre todas las fases configuradas y cancelar sus timers
        foreach ($this->phases as $phase) {
            $phaseName = $phase['name'];
            try {
                $this->timerService->cancelTimer($phaseName);
            } catch (\Exception $e) {
                // Timer no existe o ya fue cancelado - continuar
            }
        }
    }

    /**
     * Serializar a array.
     */
    public function toArray(): array
    {
        $baseArray = parent::toArray();

        return array_merge($baseArray, [
            'phases' => $this->phases,
            'current_phase_name' => $this->getCurrentPhaseName(),
        ]);
    }

    /**
     * Crear instancia desde array guardado.
     */
    public static function fromArray(array $state): self
    {
        $phases = $state['phases'] ?? [];
        $instance = new self($phases);

        $instance->currentTurnIndex = $state['current_turn_index'] ?? 0;
        $instance->isPaused = $state['is_paused'] ?? false;
        $instance->direction = $state['direction'] ?? 1;

        // Actualizar timeLimit según fase actual
        $instance->timeLimit = $phases[$instance->currentTurnIndex]['duration'] ?? null;

        return $instance;
    }
}
