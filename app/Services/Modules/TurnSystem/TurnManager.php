<?php

namespace App\Services\Modules\TurnSystem;

use Illuminate\Support\Collection;

/**
 * Turn System Module - Gestión de turnos para juegos.
 *
 * Responsabilidad: SOLO gestionar turnos (quién juega ahora).
 * NO gestiona rondas ni eliminaciones (eso es responsabilidad de RoundManager).
 *
 * Funcionalidades:
 * - Crear orden de turnos (aleatorio, secuencial, personalizado)
 * - Avanzar al siguiente turno (rotación circular)
 * - Detectar cuando se completa un ciclo (todos jugaron una vez)
 * - Pausar/reanudar turnos
 * - Invertir dirección de turnos
 * - Saltar turnos
 */
class TurnManager
{
    /**
     * Orden de jugadores (IDs).
     */
    protected array $turnOrder;

    /**
     * Índice del turno actual (0-based).
     */
    protected int $currentTurnIndex;

    /**
     * Modo de turnos: 'sequential', 'simultaneous', 'free'.
     */
    protected string $mode;

    /**
     * Indica si en el último nextTurn() se completó un ciclo.
     */
    protected bool $cycleJustCompleted = false;

    /**
     * Indica si el sistema de turnos está pausado.
     */
    protected bool $isPaused = false;

    /**
     * Dirección de los turnos: 1 = forward, -1 = backward (reversed).
     */
    protected int $direction = 1;

    /**
     * Constructor.
     *
     * @param array $playerIds Array de IDs de jugadores
     * @param string $mode Modo de turnos: 'sequential', 'shuffle', 'simultaneous', 'free'
     */
    public function __construct(
        array $playerIds,
        string $mode = 'sequential'
    ) {
        if (empty($playerIds)) {
            throw new \InvalidArgumentException('Se requiere al menos un jugador para el sistema de turnos');
        }

        $this->mode = $mode;
        $this->currentTurnIndex = 0;

        // Inicializar orden según modo
        $this->turnOrder = match ($mode) {
            'shuffle' => collect($playerIds)->shuffle()->values()->toArray(),
            'sequential' => array_values($playerIds),
            'simultaneous' => array_values($playerIds),
            'free' => array_values($playerIds),
            default => array_values($playerIds),
        };
    }

    /**
     * Obtener el ID del jugador actual.
     */
    public function getCurrentPlayer(): mixed
    {
        return $this->turnOrder[$this->currentTurnIndex];
    }

    /**
     * Obtener el índice del turno actual.
     */
    public function getCurrentTurnIndex(): int
    {
        return $this->currentTurnIndex;
    }

    /**
     * Obtener el orden completo de turnos.
     */
    public function getTurnOrder(): array
    {
        return $this->turnOrder;
    }

    /**
     * Avanzar al siguiente turno.
     *
     * Rotación circular: Al llegar al último jugador, vuelve al primero.
     *
     * @return array ['player_id', 'turn_index', 'cycle_completed']
     */
    public function nextTurn(): array
    {
        if ($this->isPaused) {
            return $this->getCurrentTurnInfo();
        }

        $this->cycleJustCompleted = false;
        $playerCount = count($this->turnOrder);

        // Incrementar/decrementar índice según dirección
        $this->currentTurnIndex += $this->direction;

        // Manejar rotación circular (forward)
        if ($this->direction === 1) {
            if ($this->currentTurnIndex >= $playerCount) {
                $this->currentTurnIndex = 0;
                $this->cycleJustCompleted = true;
            }
        }
        // Manejar rotación circular (backward)
        else {
            if ($this->currentTurnIndex < 0) {
                $this->currentTurnIndex = $playerCount - 1;
                $this->cycleJustCompleted = true;
            }
        }

        return $this->getCurrentTurnInfo();
    }

    /**
     * Verificar si se acaba de completar un ciclo.
     *
     * Un ciclo se completa cuando todos los jugadores han jugado una vez.
     * En RoundManager, esto se mapea a "completar una ronda".
     */
    public function isCycleComplete(): bool
    {
        return $this->cycleJustCompleted;
    }

    /**
     * Obtener información completa del turno actual.
     */
    public function getCurrentTurnInfo(): array
    {
        return [
            'player_id' => $this->getCurrentPlayer(),
            'turn_index' => $this->currentTurnIndex,
            'cycle_completed' => $this->cycleJustCompleted,
        ];
    }

    /**
     * Verificar si es el turno de un jugador específico.
     */
    public function isPlayerTurn(mixed $playerId): bool
    {
        return $this->getCurrentPlayer() === $playerId;
    }

    /**
     * Ver el siguiente jugador sin avanzar.
     */
    public function peekNextPlayer(): mixed
    {
        $playerCount = count($this->turnOrder);
        $nextIndex = ($this->currentTurnIndex + $this->direction) % $playerCount;

        if ($nextIndex < 0) {
            $nextIndex = $playerCount - 1;
        }

        return $this->turnOrder[$nextIndex];
    }

    /**
     * Obtener el total de jugadores.
     */
    public function getPlayerCount(): int
    {
        return count($this->turnOrder);
    }

    // ========================================================================
    // GESTIÓN DE JUGADORES
    // ========================================================================

    /**
     * Eliminar un jugador del orden de turnos.
     */
    public function removePlayer(mixed $playerId): bool
    {
        $key = array_search($playerId, $this->turnOrder);

        if ($key === false) {
            return false;
        }

        unset($this->turnOrder[$key]);
        $this->turnOrder = array_values($this->turnOrder);

        // Ajustar índice si es necesario
        if ($this->currentTurnIndex >= count($this->turnOrder) && count($this->turnOrder) > 0) {
            $this->currentTurnIndex = 0;
        }

        return true;
    }

    /**
     * Agregar un jugador al final del orden.
     */
    public function addPlayer(mixed $playerId): void
    {
        $this->turnOrder[] = $playerId;
    }

    /**
     * Reiniciar al estado inicial.
     */
    public function reset(): void
    {
        $this->currentTurnIndex = 0;
        $this->cycleJustCompleted = false;
        $this->isPaused = false;
        $this->direction = 1;
    }

    // ========================================================================
    // PAUSA/RESUME
    // ========================================================================

    /**
     * Pausar los turnos.
     */
    public function pause(): void
    {
        $this->isPaused = true;
    }

    /**
     * Reanudar los turnos.
     */
    public function resume(): void
    {
        $this->isPaused = false;
    }

    /**
     * Verificar si está pausado.
     */
    public function isPaused(): bool
    {
        return $this->isPaused;
    }

    // ========================================================================
    // DIRECCIÓN
    // ========================================================================

    /**
     * Invertir la dirección de los turnos.
     */
    public function reverse(): void
    {
        $this->direction *= -1;
    }

    /**
     * Obtener la dirección actual.
     */
    public function getDirection(): int
    {
        return $this->direction;
    }

    /**
     * Verificar si los turnos van en dirección normal.
     */
    public function isForward(): bool
    {
        return $this->direction === 1;
    }

    // ========================================================================
    // SALTAR TURNOS
    // ========================================================================

    /**
     * Saltar el turno actual y avanzar al siguiente.
     */
    public function skipTurn(): array
    {
        return $this->nextTurn();
    }

    /**
     * Saltar el turno de un jugador específico si es su turno.
     */
    public function skipPlayerTurn(mixed $playerId): bool
    {
        if ($this->getCurrentPlayer() === $playerId) {
            $this->nextTurn();
            return true;
        }

        return false;
    }

    // ========================================================================
    // UTILIDADES
    // ========================================================================

    /**
     * Obtener el modo de turnos.
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    // ========================================================================
    // SERIALIZACIÓN
    // ========================================================================

    /**
     * Serializar a array para guardar en game_state.
     */
    public function toArray(): array
    {
        return [
            'turn_order' => $this->turnOrder,
            'current_turn_index' => $this->currentTurnIndex,
            'mode' => $this->mode,
            'is_paused' => $this->isPaused,
            'direction' => $this->direction,
        ];
    }

    /**
     * Crear instancia desde un array guardado.
     */
    public static function fromArray(array $state): self
    {
        $instance = new self(
            $state['turn_order'],
            $state['mode'] ?? 'sequential'
        );

        $instance->currentTurnIndex = $state['current_turn_index'] ?? 0;
        $instance->isPaused = $state['is_paused'] ?? false;
        $instance->direction = $state['direction'] ?? 1;

        return $instance;
    }
}
