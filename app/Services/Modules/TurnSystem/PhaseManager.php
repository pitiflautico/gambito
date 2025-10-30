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
     * FLUJO ÚNICO: Iniciar/configurar la fase actual.
     *
     * Este es el ÚNICO método que debe usarse para iniciar una fase.
     * TODAS las fases (primera, intermedia, última) pasan por aquí.
     *
     * Responsabilidades:
     * 1. Emitir on_start (si está configurado)
     * 2. Crear timer con on_end (o PhaseEndedEvent por defecto)
     *
     * @return bool True si se inició correctamente
     */
    public function startPhase(): bool
    {
        if (!$this->match) {
            \Log::warning("⚠️ [PhaseManager] Cannot start phase - match not set");
            return false;
        }

        $currentPhaseConfig = $this->getCurrentPhase();
        $phaseName = $currentPhaseConfig['name'];
        $duration = $currentPhaseConfig['duration'] ?? null;

        \Log::info("🎬 [PhaseManager] Starting phase", [
            'phase' => $phaseName,
            'duration' => $duration,
            'match_id' => $this->match->id
        ]);

        // HOOK 1: on_start (opcional)
        if (isset($currentPhaseConfig['on_start'])) {
            $onStartEvent = $currentPhaseConfig['on_start'];
            if (class_exists($onStartEvent)) {
                \Log::info("🎯 [PhaseManager] Emitting on_start event", [
                    'event' => $onStartEvent,
                    'phase' => $phaseName
                ]);
                event(new $onStartEvent($this->match, $currentPhaseConfig));
            }
        }

        // Si no hay duración, no hay timer
        if ($duration === null || $this->timerService === null) {
            \Log::info("⏭️ [PhaseManager] Phase has no timer", ['phase' => $phaseName]);
            return true;
        }

        // Cancelar timer anterior si existe
        if ($this->timerService->hasTimer($phaseName)) {
            $this->timerService->cancelTimer($phaseName);
        }

        // HOOK 2: on_end (con fallback a PhaseEndedEvent)
        $onEndEvent = $currentPhaseConfig['on_end'] ?? \App\Events\Game\PhaseEndedEvent::class;

        $eventData = [
            'match_id' => $this->match->id,
            'phaseConfig' => $currentPhaseConfig
        ];

        \Log::info("⏱️ [PhaseManager] Creating phase timer", [
            'phase' => $phaseName,
            'duration' => $duration,
            'event_to_emit' => $onEndEvent
        ]);

        $this->timerService->startTimer(
            timerName: $phaseName,
            durationSeconds: $duration,
            eventToEmit: $onEndEvent,
            eventData: $eventData
        );

        return true;
    }

    /**
     * Avanzar a la siguiente fase.
     *
     * @return array ['phase_name', 'phase_index', 'cycle_completed', 'duration']
     */
    public function nextPhase(): array
    {
        // Avanzar al siguiente índice
        $turnInfo = parent::nextTurn();
        $currentPhaseConfig = $this->getCurrentPhase();

        // Si el ciclo se completó, NO iniciar la fase (la ronda va a terminar)
        if (!$turnInfo['cycle_completed']) {
            $this->startPhase();
        } else {
            \Log::info("🔄 [PhaseManager] Cycle completed - NOT starting phase (round will end)", [
                'phase' => $currentPhaseConfig['name'],
                'match_id' => $this->match ? $this->match->id : null
            ]);
        }

        return [
            'phase_name' => $currentPhaseConfig['name'],
            'phase_index' => $this->currentTurnIndex,
            'cycle_completed' => $turnInfo['cycle_completed'],
            'duration' => $currentPhaseConfig['duration'] ?? null,
        ];
    }

    /**
     * LEGACY: Override para mantener compatibilidad.
     * ELIMINADO - ahora solo existe startPhase() como punto único de entrada.
     *
     * @deprecated No usar - usar startPhase() directamente
     */
    public function startTurnTimer(): bool
    {
        // NO hacer nada - startPhase() ya fue llamado
        // Este método solo existe por compatibilidad con TurnManager padre
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
    /**
     * DEPRECATED: Este método es obsoleto con el nuevo flujo.
     * Ahora startPhase() configura el timer con el evento correcto directamente.
     *
     * @deprecated Mantener por compatibilidad temporal
     */
    public function triggerPhaseExpired(): bool
    {
        $currentPhaseName = $this->getCurrentPhaseName();
        $currentPhaseConfig = $this->phases[$this->currentTurnIndex] ?? null;
        $hasCallback = isset($this->phaseCallbacks[$currentPhaseName]);

        // Emitir SOLO evento personalizado si existe, SINO emitir genérico
        if ($currentPhaseConfig && isset($currentPhaseConfig['on_end']) && $this->match) {
            $onEndEvent = $currentPhaseConfig['on_end'];
            if ($onEndEvent && class_exists($onEndEvent)) {
                \Log::info("🏁 [PhaseManager] Emitting CUSTOM on_end event", [
                    'event' => $onEndEvent,
                    'phase' => $currentPhaseName,
                    'match_id' => $this->match->id
                ]);

                event(new $onEndEvent($this->match, $currentPhaseConfig));

                // NO emitir evento genérico si hay personalizado
                return true;
            }
        }

        // Si NO hay evento personalizado, emitir genérico
        if ($this->match !== null) {
            \Log::info("🏁 [PhaseManager] Emitting GENERIC PhaseTimerExpiredEvent", [
                'phase' => $currentPhaseName
            ]);

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
