<?php

namespace App\Services\Modules\PlayerStateSystem;

/**
 * Gestor unificado del estado de jugadores.
 *
 * Este módulo gestiona TODO el estado individual de cada jugador:
 * - Roles persistentes (todo el juego): detective, mafia, etc.
 * - Roles de ronda (temporales): dibujante, votante actual, etc.
 * - Estado de bloqueo: ¿Puede actuar o ya actuó esta ronda?
 * - Acciones: ¿Qué hizo el jugador esta ronda?
 * - Estados custom: waiting, active, eliminated, etc.
 * - Intentos/Vidas: Para juegos que lo necesiten
 *
 * SEPARACIÓN DE RESPONSABILIDADES:
 * - PlayerStateManager: Estado individual de jugadores
 * - ScoreManager: Puntuaciones (acumulativo con history)
 * - TurnManager: Orden de turnos
 * - RoundManager: Contador de rondas
 *
 * RESETEO:
 * - reset(): Limpia estado temporal (locks, actions, roundRoles)
 * - NO resetea roles persistentes ni scores
 */
class PlayerStateManager
{
    // ========================================================================
    // ROLES PERSISTENTES (todo el juego, NO se resetean)
    // ========================================================================

    /**
     * Roles disponibles en el juego.
     *
     * @var array<string>
     */
    protected array $availableRoles = [];

    /**
     * Mapeo de jugador => rol persistente.
     *
     * @var array<int, string|array<string>>
     */
    protected array $persistentRoles = [];

    /**
     * Si permite múltiples roles persistentes por jugador.
     *
     * @var bool
     */
    protected bool $allowMultiplePersistentRoles;

    // ========================================================================
    // ESTADO TEMPORAL (se resetea cada ronda)
    // ========================================================================

    /**
     * Roles de ronda (temporales, cambian cada ronda).
     *
     * Ejemplo: En Pictionary, "drawer" rota cada ronda.
     *
     * @var array<int, string>
     */
    protected array $roundRoles = [];

    /**
     * Jugadores bloqueados (ya actuaron esta ronda).
     *
     * @var array<int, bool>
     */
    protected array $locks = [];

    /**
     * Acciones realizadas por jugadores esta ronda.
     *
     * @var array<int, array>
     */
    protected array $actions = [];

    /**
     * Estados custom de jugadores.
     *
     * Ejemplo: 'waiting', 'active', 'eliminated', 'drawing'
     *
     * @var array<int, string>
     */
    protected array $states = [];

    /**
     * Intentos o vidas de jugadores.
     *
     * @var array<int, int>
     */
    protected array $attempts = [];

    // ========================================================================
    // CONSTRUCTOR
    // ========================================================================

    public function __construct(
        array $availableRoles = [],
        bool $allowMultiplePersistentRoles = false,
        array $persistentRoles = [],
        array $roundRoles = [],
        array $locks = [],
        array $actions = [],
        array $states = [],
        array $attempts = []
    ) {
        $this->availableRoles = $availableRoles;
        $this->allowMultiplePersistentRoles = $allowMultiplePersistentRoles;
        $this->persistentRoles = $persistentRoles;
        $this->roundRoles = $roundRoles;
        $this->locks = $locks;
        $this->actions = $actions;
        $this->states = $states;
        $this->attempts = $attempts;
    }

    // ========================================================================
    // ROLES PERSISTENTES (todo el juego)
    // ========================================================================

    /**
     * Asignar rol persistente a un jugador.
     *
     * @param int $playerId
     * @param string $role
     * @return void
     * @throws \InvalidArgumentException Si el rol no existe
     */
    public function assignPersistentRole(int $playerId, string $role): void
    {
        if (!empty($this->availableRoles) && !in_array($role, $this->availableRoles)) {
            throw new \InvalidArgumentException("Role '{$role}' is not available in this game");
        }

        if ($this->allowMultiplePersistentRoles) {
            if (!isset($this->persistentRoles[$playerId])) {
                $this->persistentRoles[$playerId] = [];
            }

            if (!in_array($role, $this->persistentRoles[$playerId])) {
                $this->persistentRoles[$playerId][] = $role;
            }
        } else {
            $this->persistentRoles[$playerId] = $role;
        }
    }

    /**
     * Obtener rol persistente de un jugador.
     *
     * @param int $playerId
     * @return string|array|null
     */
    public function getPersistentRole(int $playerId): string|array|null
    {
        return $this->persistentRoles[$playerId] ?? null;
    }

    /**
     * Verificar si un jugador tiene un rol persistente específico.
     *
     * @param int $playerId
     * @param string $role
     * @return bool
     */
    public function hasPersistentRole(int $playerId, string $role): bool
    {
        if (!isset($this->persistentRoles[$playerId])) {
            return false;
        }

        if ($this->allowMultiplePersistentRoles) {
            return in_array($role, $this->persistentRoles[$playerId]);
        }

        return $this->persistentRoles[$playerId] === $role;
    }

    /**
     * Obtener todos los jugadores con un rol persistente específico.
     *
     * @param string $role
     * @return array<int>
     */
    public function getPlayersWithPersistentRole(string $role): array
    {
        $players = [];

        foreach ($this->persistentRoles as $playerId => $playerRole) {
            if ($this->allowMultiplePersistentRoles) {
                if (in_array($role, $playerRole)) {
                    $players[] = $playerId;
                }
            } else {
                if ($playerRole === $role) {
                    $players[] = $playerId;
                }
            }
        }

        return $players;
    }

    /**
     * Remover rol persistente de un jugador.
     *
     * @param int $playerId
     * @param string|null $role Rol específico o null para remover todos
     * @return void
     */
    public function removePersistentRole(int $playerId, ?string $role = null): void
    {
        if (!isset($this->persistentRoles[$playerId])) {
            return;
        }

        if ($role === null) {
            unset($this->persistentRoles[$playerId]);
            return;
        }

        if ($this->allowMultiplePersistentRoles) {
            $this->persistentRoles[$playerId] = array_values(
                array_filter(
                    $this->persistentRoles[$playerId],
                    fn($r) => $r !== $role
                )
            );

            if (empty($this->persistentRoles[$playerId])) {
                unset($this->persistentRoles[$playerId]);
            }
        } else {
            if ($this->persistentRoles[$playerId] === $role) {
                unset($this->persistentRoles[$playerId]);
            }
        }
    }

    // ========================================================================
    // ROLES DE RONDA (temporales, se resetean cada ronda)
    // ========================================================================

    /**
     * Asignar rol de ronda a un jugador.
     *
     * @param int $playerId
     * @param string $role
     * @return void
     */
    public function assignRoundRole(int $playerId, string $role): void
    {
        $this->roundRoles[$playerId] = $role;
    }

    /**
     * Obtener rol de ronda de un jugador.
     *
     * @param int $playerId
     * @return string|null
     */
    public function getRoundRole(int $playerId): ?string
    {
        return $this->roundRoles[$playerId] ?? null;
    }

    /**
     * Verificar si un jugador tiene un rol de ronda específico.
     *
     * @param int $playerId
     * @param string $role
     * @return bool
     */
    public function hasRoundRole(int $playerId, string $role): bool
    {
        return ($this->roundRoles[$playerId] ?? null) === $role;
    }

    /**
     * Obtener todos los jugadores con un rol de ronda específico.
     *
     * @param string $role
     * @return array<int>
     */
    public function getPlayersWithRoundRole(string $role): array
    {
        $players = [];

        foreach ($this->roundRoles as $playerId => $playerRole) {
            if ($playerRole === $role) {
                $players[] = $playerId;
            }
        }

        return $players;
    }

    /**
     * Remover rol de ronda de un jugador.
     *
     * @param int $playerId
     * @return void
     */
    public function removeRoundRole(int $playerId): void
    {
        unset($this->roundRoles[$playerId]);
    }

    /**
     * Limpiar todos los roles de ronda.
     *
     * @return void
     */
    public function clearAllRoundRoles(): void
    {
        $this->roundRoles = [];
    }

    // ========================================================================
    // ROTACIÓN DE ROLES
    // ========================================================================

    /**
     * Rotar rol de ronda de forma secuencial.
     *
     * El rol pasa al siguiente jugador en el orden proporcionado.
     *
     * @param string $role Rol a rotar (ej: 'drawer')
     * @param array<int> $playerIds Orden de jugadores
     * @param int $currentRound Número de ronda actual (para calcular siguiente)
     * @return int ID del jugador que recibe el rol
     */
    public function rotateRoleSequential(string $role, array $playerIds, int $currentRound): int
    {
        // Calcular índice del siguiente jugador basado en la ronda
        $nextIndex = ($currentRound - 1) % count($playerIds);
        $nextPlayerId = $playerIds[$nextIndex];

        // Asignar rol al siguiente jugador
        $this->assignRoundRole($nextPlayerId, $role);

        return $nextPlayerId;
    }

    /**
     * Rotar rol de ronda de forma aleatoria.
     *
     * El rol se asigna a un jugador aleatorio.
     *
     * @param string $role Rol a asignar (ej: 'drawer')
     * @param array<int> $playerIds IDs de jugadores elegibles
     * @return int ID del jugador que recibe el rol
     */
    public function rotateRoleRandom(string $role, array $playerIds): int
    {
        $randomPlayerId = $playerIds[array_rand($playerIds)];
        $this->assignRoundRole($randomPlayerId, $role);

        return $randomPlayerId;
    }

    /**
     * Asignar el mismo rol persistente a todos los jugadores.
     *
     * Útil para juegos donde todos tienen el mismo rol siempre.
     *
     * @param string $role Rol a asignar (ej: 'guesser')
     * @param array<int> $playerIds IDs de todos los jugadores
     * @return void
     */
    public function assignSameRoleToAll(string $role, array $playerIds): void
    {
        foreach ($playerIds as $playerId) {
            $this->assignPersistentRole($playerId, $role);
        }
    }

    /**
     * Rotar rol usando una función callback custom.
     *
     * Permite lógica de rotación completamente personalizada.
     *
     * @param string $role Rol a rotar
     * @param array<int> $playerIds IDs de jugadores elegibles
     * @param callable $callback Función que recibe (role, playerIds, roundRoles) y retorna playerId
     * @return int ID del jugador que recibe el rol
     */
    public function rotateRoleCustom(string $role, array $playerIds, callable $callback): int
    {
        $selectedPlayerId = $callback($role, $playerIds, $this->roundRoles);
        $this->assignRoundRole($selectedPlayerId, $role);

        return $selectedPlayerId;
    }

    /**
     * Rotar múltiples roles complementarios.
     *
     * Útil para juegos con roles que se excluyen mutuamente (ej: drawer vs guessers).
     *
     * @param array $roleConfig Configuración de roles
     *   Ejemplo: ['drawer' => 1, 'guesser' => '*'] significa 1 drawer, el resto guessers
     * @param array<int> $playerIds IDs de todos los jugadores
     * @param int $currentRound Número de ronda actual
     * @param string $rotationType Tipo: 'sequential', 'random'
     * @return array<string, array<int>> Mapeo de rol => [playerIds]
     */
    public function rotateComplementaryRoles(
        array $roleConfig,
        array $playerIds,
        int $currentRound,
        string $rotationType = 'sequential'
    ): array {
        $assignments = [];

        foreach ($roleConfig as $role => $count) {
            if ($count === '*') {
                // Asignar este rol a todos los jugadores restantes
                $alreadyAssigned = count($assignments) > 0
                    ? array_merge(...array_values($assignments))
                    : [];
                $remaining = array_diff($playerIds, $alreadyAssigned);
                foreach ($remaining as $playerId) {
                    $this->assignRoundRole($playerId, $role);
                }
                $assignments[$role] = array_values($remaining);
            } elseif (is_int($count)) {
                // Asignar este rol a N jugadores
                $assigned = [];
                for ($i = 0; $i < $count; $i++) {
                    if ($rotationType === 'sequential') {
                        $index = (($currentRound - 1) + $i) % count($playerIds);
                        $playerId = $playerIds[$index];
                    } else { // random
                        $alreadyAssigned = count($assignments) > 0
                            ? array_merge(...array_values($assignments))
                            : [];
                        $available = array_diff($playerIds, array_merge($alreadyAssigned, $assigned));
                        $playerId = $available[array_rand($available)];
                    }

                    $this->assignRoundRole($playerId, $role);
                    $assigned[] = $playerId;
                }
                $assignments[$role] = $assigned;
            }
        }

        return $assignments;
    }

    // ========================================================================
    // BLOQUEO (locks)
    // ========================================================================

    /**
     * Bloquear jugador (ya actuó esta ronda).
     *
     * @param int $playerId
     * @return void
     */
    public function lockPlayer(int $playerId): void
    {
        $this->locks[$playerId] = true;
    }

    /**
     * Desbloquear jugador.
     *
     * @param int $playerId
     * @return void
     */
    public function unlockPlayer(int $playerId): void
    {
        unset($this->locks[$playerId]);
    }

    /**
     * Desbloquear todos los jugadores.
     *
     * @return void
     */
    public function unlockAllPlayers(): void
    {
        $this->locks = [];
    }

    /**
     * Verificar si un jugador está bloqueado.
     *
     * @param int $playerId
     * @return bool
     */
    public function isPlayerLocked(int $playerId): bool
    {
        return $this->locks[$playerId] ?? false;
    }

    /**
     * Obtener todos los jugadores bloqueados.
     *
     * @return array<int>
     */
    public function getLockedPlayers(): array
    {
        return array_keys(array_filter($this->locks));
    }

    // ========================================================================
    // ACCIONES
    // ========================================================================

    /**
     * Registrar acción de un jugador.
     *
     * @param int $playerId
     * @param array $action Datos de la acción
     * @return void
     */
    public function setPlayerAction(int $playerId, array $action): void
    {
        $this->actions[$playerId] = $action;
    }

    /**
     * Obtener acción de un jugador.
     *
     * @param int $playerId
     * @return array|null
     */
    public function getPlayerAction(int $playerId): ?array
    {
        return $this->actions[$playerId] ?? null;
    }

    /**
     * Obtener todas las acciones.
     *
     * @return array<int, array>
     */
    public function getAllActions(): array
    {
        return $this->actions;
    }

    /**
     * Verificar si un jugador ha actuado.
     *
     * @param int $playerId
     * @return bool
     */
    public function hasPlayerActed(int $playerId): bool
    {
        return isset($this->actions[$playerId]);
    }

    /**
     * Limpiar todas las acciones.
     *
     * @return void
     */
    public function clearAllActions(): void
    {
        $this->actions = [];
    }

    // ========================================================================
    // ESTADOS CUSTOM
    // ========================================================================

    /**
     * Establecer estado de un jugador.
     *
     * @param int $playerId
     * @param string $state Ejemplo: 'waiting', 'active', 'eliminated'
     * @return void
     */
    public function setPlayerState(int $playerId, string $state): void
    {
        $this->states[$playerId] = $state;
    }

    /**
     * Obtener estado de un jugador.
     *
     * @param int $playerId
     * @return string|null
     */
    public function getPlayerState(int $playerId): ?string
    {
        return $this->states[$playerId] ?? null;
    }

    /**
     * Obtener todos los jugadores con un estado específico.
     *
     * @param string $state
     * @return array<int>
     */
    public function getPlayersWithState(string $state): array
    {
        return array_keys(array_filter(
            $this->states,
            fn($s) => $s === $state
        ));
    }

    /**
     * Limpiar estados de todos los jugadores.
     *
     * @return void
     */
    public function clearAllStates(): void
    {
        $this->states = [];
    }

    // ========================================================================
    // INTENTOS/VIDAS
    // ========================================================================

    /**
     * Incrementar intentos de un jugador.
     *
     * @param int $playerId
     * @return int Nuevo número de intentos
     */
    public function incrementAttempts(int $playerId): int
    {
        if (!isset($this->attempts[$playerId])) {
            $this->attempts[$playerId] = 0;
        }

        return ++$this->attempts[$playerId];
    }

    /**
     * Obtener intentos de un jugador.
     *
     * @param int $playerId
     * @return int
     */
    public function getAttempts(int $playerId): int
    {
        return $this->attempts[$playerId] ?? 0;
    }

    /**
     * Resetear intentos de un jugador.
     *
     * @param int $playerId
     * @return void
     */
    public function resetAttempts(int $playerId): void
    {
        $this->attempts[$playerId] = 0;
    }

    /**
     * Limpiar todos los intentos.
     *
     * @return void
     */
    public function clearAllAttempts(): void
    {
        $this->attempts = [];
    }

    // ========================================================================
    // RESETEO
    // ========================================================================

    /**
     * Resetear estado temporal (al iniciar nueva ronda).
     *
     * Limpia:
     * - Roles de ronda
     * - Bloqueos
     * - Acciones
     * - Estados
     * - Intentos
     *
     * NO limpia:
     * - Roles persistentes (se mantienen todo el juego)
     *
     * @return void
     */
    public function reset(): void
    {
        $this->roundRoles = [];
        $this->locks = [];
        $this->actions = [];
        $this->states = [];
        $this->attempts = [];
    }

    // ========================================================================
    // SERIALIZACIÓN
    // ========================================================================

    /**
     * Serializar a array para guardar en game_state.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'player_state_system' => [
                // PERSISTENTE (no se resetea)
                'available_roles' => $this->availableRoles,
                'allow_multiple_persistent_roles' => $this->allowMultiplePersistentRoles,
                'persistent_roles' => $this->persistentRoles,

                // TEMPORAL (se resetea cada ronda)
                'round_roles' => $this->roundRoles,
                'locks' => $this->locks,
                'actions' => $this->actions,
                'states' => $this->states,
                'attempts' => $this->attempts,
            ]
        ];
    }

    /**
     * Restaurar desde array serializado.
     *
     * @param array $data Estado serializado
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $stateData = $data['player_state_system'] ?? $data;

        return new self(
            availableRoles: $stateData['available_roles'] ?? [],
            allowMultiplePersistentRoles: $stateData['allow_multiple_persistent_roles'] ?? false,
            persistentRoles: $stateData['persistent_roles'] ?? [],
            roundRoles: $stateData['round_roles'] ?? [],
            locks: $stateData['locks'] ?? [],
            actions: $stateData['actions'] ?? [],
            states: $stateData['states'] ?? [],
            attempts: $stateData['attempts'] ?? []
        );
    }
}
