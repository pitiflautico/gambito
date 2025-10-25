<?php

namespace App\Services\Modules\TurnSystem;

use App\Services\Modules\TimerSystem\TimerService;
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
     * Límite de tiempo por turno en segundos (null = sin límite).
     */
    protected ?int $timeLimit = null;

    /**
     * TimerService para manejar el timer del turno.
     */
    protected ?TimerService $timerService = null;

    /**
     * Constructor.
     *
     * @param array $playerIds Array de IDs de jugadores
     * @param string $mode Modo de turnos: 'sequential', 'shuffle', 'simultaneous', 'free'
     * @param int|null $timeLimit Límite de tiempo por turno en segundos (null = sin límite)
     */
    public function __construct(
        array $playerIds,
        string $mode = 'sequential',
        ?int $timeLimit = null
    ) {
        if (empty($playerIds)) {
            throw new \InvalidArgumentException('Se requiere al menos un jugador para el sistema de turnos');
        }

        $this->mode = $mode;
        $this->currentTurnIndex = 0;
        $this->timeLimit = $timeLimit;

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

        // Limpiar completions del turno anterior
        $this->turnCompletions = [];

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

        // Iniciar timer del turno automáticamente (si está configurado)
        $this->startTurnTimer();

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

        // Iniciar timer del primer turno automáticamente (si está configurado)
        $this->startTurnTimer();
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
            'time_limit' => $this->timeLimit,
        ];
    }

    /**
     * Crear instancia desde un array guardado.
     */
    public static function fromArray(array $state): self
    {
        $instance = new self(
            $state['turn_order'],
            $state['mode'] ?? 'sequential',
            $state['time_limit'] ?? null
        );

        $instance->currentTurnIndex = $state['current_turn_index'] ?? 0;
        $instance->isPaused = $state['is_paused'] ?? false;
        $instance->direction = $state['direction'] ?? 1;

        return $instance;
    }

    // ========================================================================
    // INTEGRACIÓN CON EQUIPOS
    // ========================================================================

    /**
     * Establecer el TeamsManager para juegos por equipos
     */
    public function setTeamsManager(?TeamsManager $teamsManager): void
    {
        $this->teamsManager = $teamsManager;
    }

    /**
     * Obtener el TeamsManager
     */
    public function getTeamsManager(): ?TeamsManager
    {
        return $this->teamsManager;
    }

    /**
     * Verificar si el juego se está jugando por equipos
     */
    public function isTeamsMode(): bool
    {
        return $this->teamsManager && $this->teamsManager->isEnabled();
    }

    /**
     * Avanzar al siguiente turno considerando equipos
     *
     * En modo "team_turns":
     * - Avanza al siguiente equipo en lugar de siguiente jugador
     * - Actualiza el equipo actual en TeamsManager
     *
     * @return array Info del turno con datos del equipo si aplica
     */
    public function nextTeamTurn(): array
    {
        if (!$this->isTeamsMode()) {
            return $this->nextTurn();
        }

        $mode = $this->teamsManager->getMode();

        if ($mode === 'team_turns') {
            // Avanzar al siguiente equipo
            $nextTeam = $this->teamsManager->nextTeam();

            return [
                'player_id' => null, // En team_turns no hay un solo jugador actual
                'team_id' => $nextTeam['id'] ?? null,
                'team' => $nextTeam,
                'turn_index' => $this->currentTurnIndex,
                'cycle_completed' => $this->cycleJustCompleted,
                'mode' => 'team_turns'
            ];
        }

        // Para otros modos, usar comportamiento normal
        return $this->nextTurn();
    }

    /**
     * Obtener equipo del jugador actual
     *
     * @return array|null Datos del equipo o null
     */
    public function getCurrentPlayerTeam(): ?array
    {
        if (!$this->isTeamsMode()) {
            return null;
        }

        $currentPlayerId = $this->getCurrentPlayer();
        return $this->teamsManager->getPlayerTeam($currentPlayerId);
    }

    // ========================================================================
    // TRACKING DE COMPLETIONS (Para equipos)
    // ========================================================================

    /**
     * Marcar que un jugador completó su acción en el turno actual
     *
     * @param mixed $playerId ID del jugador
     */
    public function markPlayerCompleted(mixed $playerId): void
    {
        $this->turnCompletions[$playerId] = true;
    }

    /**
     * Verificar si un jugador ya completó su acción en el turno actual
     *
     * @param mixed $playerId ID del jugador
     * @return bool
     */
    public function hasPlayerCompleted(mixed $playerId): bool
    {
        return isset($this->turnCompletions[$playerId]);
    }

    /**
     * Obtener lista de jugadores que completaron
     *
     * @return array IDs de jugadores
     */
    public function getCompletedPlayers(): array
    {
        return array_keys($this->turnCompletions);
    }

    /**
     * Limpiar tracking de completions (útil al iniciar nuevo turno)
     */
    public function clearCompletions(): void
    {
        $this->turnCompletions = [];
    }

    /**
     * Configurar si se requiere que todos los miembros del equipo completen
     *
     * @param bool $required
     */
    public function setRequireAllTeamMembers(bool $required): void
    {
        $this->requireAllTeamMembers = $required;
    }

    /**
     * Verificar si el turno actual está completo (considerando equipos)
     *
     * Lógica según modo:
     * - Sin equipos: Siempre true (turno individual)
     * - team_turns + requireAllTeamMembers=false: true cuando 1+ miembro completa
     * - team_turns + requireAllTeamMembers=true: true cuando TODOS los miembros completan
     * - all_teams: true cuando todos los equipos tienen al menos 1 respuesta
     *
     * @return array ['is_complete' => bool, 'reason' => string, 'completed_count' => int, 'total_count' => int]
     */
    public function isTurnComplete(): array
    {
        // Sin equipos: turno siempre está completo (comportamiento clásico)
        if (!$this->isTeamsMode()) {
            return [
                'is_complete' => true,
                'reason' => 'individual_turn',
                'completed_count' => 1,
                'total_count' => 1
            ];
        }

        $mode = $this->teamsManager->getMode();

        // Modo team_turns: Verificar si el equipo actual completó
        if ($mode === 'team_turns') {
            return $this->isTurnCompleteTeamTurns();
        }

        // Modo all_teams: Verificar si todos los equipos tienen respuestas
        if ($mode === 'all_teams') {
            return $this->isTurnCompleteAllTeams();
        }

        // Otros modos: comportamiento individual
        return [
            'is_complete' => true,
            'reason' => 'sequential_individual',
            'completed_count' => count($this->turnCompletions),
            'total_count' => 1
        ];
    }

    /**
     * Verificar si el turno está completo en modo team_turns
     */
    protected function isTurnCompleteTeamTurns(): array
    {
        $currentTeam = $this->teamsManager->getCurrentTeam();

        if (!$currentTeam) {
            return [
                'is_complete' => false,
                'reason' => 'no_current_team',
                'completed_count' => 0,
                'total_count' => 0
            ];
        }

        $teamMembers = $currentTeam['members'] ?? [];
        $completedMembers = array_filter(
            $teamMembers,
            fn($memberId) => $this->hasPlayerCompleted($memberId)
        );

        $completedCount = count($completedMembers);
        $totalCount = count($teamMembers);

        // Si se requiere que todos completen
        if ($this->requireAllTeamMembers) {
            return [
                'is_complete' => $completedCount === $totalCount,
                'reason' => 'require_all_members',
                'completed_count' => $completedCount,
                'total_count' => $totalCount,
                'team_id' => $currentTeam['id']
            ];
        }

        // Si solo se requiere al menos uno
        return [
            'is_complete' => $completedCount > 0,
            'reason' => 'at_least_one_member',
            'completed_count' => $completedCount,
            'total_count' => $totalCount,
            'team_id' => $currentTeam['id']
        ];
    }

    /**
     * Verificar si el turno está completo en modo all_teams
     */
    protected function isTurnCompleteAllTeams(): array
    {
        $teams = $this->teamsManager->getTeams();
        $teamsWithResponses = 0;

        foreach ($teams as $team) {
            $teamMembers = $team['members'] ?? [];
            $hasAnyResponse = false;

            foreach ($teamMembers as $memberId) {
                if ($this->hasPlayerCompleted($memberId)) {
                    $hasAnyResponse = true;
                    break;
                }
            }

            if ($hasAnyResponse) {
                $teamsWithResponses++;
            }
        }

        $totalTeams = count($teams);

        return [
            'is_complete' => $teamsWithResponses === $totalTeams,
            'reason' => 'all_teams_responded',
            'completed_count' => $teamsWithResponses,
            'total_count' => $totalTeams
        ];
    }

    /**
     * Verificar si se puede avanzar al siguiente turno
     *
     * Combina isPaused + isTurnComplete para decidir si es válido llamar nextTurn()
     *
     * @return array ['can_advance' => bool, 'reason' => string, 'details' => array]
     */
    public function canAdvanceTurn(): array
    {
        if ($this->isPaused) {
            return [
                'can_advance' => false,
                'reason' => 'paused',
                'details' => []
            ];
        }

        $turnStatus = $this->isTurnComplete();

        return [
            'can_advance' => $turnStatus['is_complete'],
            'reason' => $turnStatus['is_complete'] ? 'turn_complete' : 'turn_incomplete',
            'details' => $turnStatus
        ];
    }

    // ========================================================================
    // TIMING DEL TURNO (Integración con TimingModule)
    // ========================================================================

    /**
     * Establecer el TimerService para manejar timers del turno.
     *
     * @param TimerService|null $timerService
     */
    public function setTimerService(?TimerService $timerService): void
    {
        $this->timerService = $timerService;
    }

    /**
     * Obtener el TimerService actual.
     *
     * @return TimerService|null
     */
    public function getTimerService(): ?TimerService
    {
        return $this->timerService;
    }

    /**
     * Iniciar el timer del turno si está configurado.
     *
     * Este método crea un timer en TimerService que se encarga de:
     * - Trackear el tiempo transcurrido
     * - Exponer tiempo restante para el frontend
     * - Emitir eventos cuando expira (si se configura)
     *
     * @return bool True si se inició el timer, false si no hay límite o TimerService
     */
    public function startTurnTimer(): bool
    {
        if ($this->timeLimit === null || $this->timerService === null) {
            \Log::debug('❌ startTurnTimer: Cannot start timer', [
                'timeLimit' => $this->timeLimit,
                'hasTimerService' => $this->timerService !== null
            ]);
            return false;
        }

        // Cancelar timer anterior si existe
        if ($this->timerService->hasTimer('turn_timer')) {
            $this->timerService->cancelTimer('turn_timer');
        }

        // Crear nuevo timer del turno
        $this->timerService->startTimer('turn_timer', $this->timeLimit);

        \Log::info('✅ Turn timer started', [
            'timeLimit' => $this->timeLimit,
            'timer_name' => 'turn_timer'
        ]);

        return true;
    }

    /**
     * Obtener el tiempo restante del turno actual en segundos.
     *
     * @return int|null Segundos restantes o null si no hay límite de tiempo
     */
    public function getRemainingTime(): ?int
    {
        if ($this->timeLimit === null || $this->timerService === null) {
            return null;
        }

        if (!$this->timerService->hasTimer('turn_timer')) {
            return null;
        }

        return $this->timerService->getRemainingTime('turn_timer');
    }

    /**
     * Verificar si el tiempo del turno ha expirado.
     *
     * @return bool True si expiró, false si no o si no hay límite
     */
    public function isTimeExpired(): bool
    {
        if ($this->timeLimit === null || $this->timerService === null) {
            return false;
        }

        if (!$this->timerService->hasTimer('turn_timer')) {
            return false;
        }

        return $this->timerService->isExpired('turn_timer');
    }

    /**
     * Cancelar el timer del turno.
     */
    public function cancelTurnTimer(): void
    {
        if ($this->timerService !== null && $this->timerService->hasTimer('turn_timer')) {
            $this->timerService->cancelTimer('turn_timer');
        }
    }

    /**
     * Obtener el límite de tiempo configurado.
     *
     * @return int|null Segundos de límite o null
     */
    public function getTimeLimit(): ?int
    {
        return $this->timeLimit;
    }

    /**
     * Verificar si el turno tiene límite de tiempo.
     *
     * @return bool
     */
    public function hasTimeLimit(): bool
    {
        return $this->timeLimit !== null;
    }

    /**
     * Obtener información completa del timing del turno para el frontend.
     *
     * Esto se puede incluir en eventos (ej: RoundStartedEvent) para que
     * el frontend muestre un countdown automáticamente.
     *
     * @return array|null Info del timing o null si no hay límite
     */
    public function getTimingInfo(): ?array
    {
        if (!$this->hasTimeLimit()) {
            return null;
        }

        $remaining = $this->getRemainingTime();

        return [
            'type' => 'countdown',
            'delay' => $this->timeLimit,
            'remaining' => $remaining,
            'is_expired' => $this->isTimeExpired(),
            'warning_threshold' => 5, // Últimos 5 segundos en rojo
        ];
    }
}
