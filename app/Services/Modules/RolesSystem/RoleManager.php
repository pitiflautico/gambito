<?php

namespace App\Services\Modules\RolesSystem;

/**
 * Servicio genérico para gestionar roles de jugadores en juegos.
 *
 * Este módulo permite asignar, rotar y consultar roles de jugadores
 * de forma genérica. Es útil para juegos que necesitan:
 * - Roles dinámicos que cambian por turno (ej. Pictionary: drawer/guesser)
 * - Roles fijos durante toda la partida (ej. Mafia: detective/mafia/civil)
 * - Múltiples roles simultáneos por jugador
 * - Permisos y capacidades por rol
 *
 * Características:
 * - Asignación de roles a jugadores
 * - Consulta rápida de rol por jugador
 * - Obtener jugadores por rol
 * - Rotación de roles (para juegos por turnos)
 * - Serialización completa
 *
 * Nota: Este servicio NO gestiona permisos (eso es responsabilidad del juego).
 * Solo mantiene el tracking de quién tiene qué rol.
 */
class RoleManager
{
    /**
     * Mapeo de jugador => rol(es).
     *
     * @var array<int, string|array<string>>
     */
    protected array $playerRoles = [];

    /**
     * Roles disponibles en el juego.
     *
     * @var array<string>
     */
    protected array $availableRoles = [];

    /**
     * Si permite múltiples roles por jugador.
     *
     * @var bool
     */
    protected bool $allowMultipleRoles;

    /**
     * Constructor.
     *
     * @param array<string> $availableRoles Roles disponibles en el juego
     * @param bool $allowMultipleRoles Si un jugador puede tener múltiples roles
     * @param array<int, string|array<string>> $playerRoles Estado inicial de roles
     */
    public function __construct(
        array $availableRoles,
        bool $allowMultipleRoles = false,
        array $playerRoles = []
    ) {
        $this->availableRoles = $availableRoles;
        $this->allowMultipleRoles = $allowMultipleRoles;
        $this->playerRoles = $playerRoles;
    }

    /**
     * Asignar un rol a un jugador.
     *
     * @param int $playerId ID del jugador
     * @param string $role Rol a asignar
     * @return void
     * @throws \InvalidArgumentException Si el rol no existe
     */
    public function assignRole(int $playerId, string $role): void
    {
        if (!in_array($role, $this->availableRoles)) {
            throw new \InvalidArgumentException("Role '{$role}' is not available in this game");
        }

        if ($this->allowMultipleRoles) {
            // Múltiples roles: array de roles
            if (!isset($this->playerRoles[$playerId])) {
                $this->playerRoles[$playerId] = [];
            }

            if (!in_array($role, $this->playerRoles[$playerId])) {
                $this->playerRoles[$playerId][] = $role;
            }
        } else {
            // Un solo rol: string
            $this->playerRoles[$playerId] = $role;
        }
    }

    /**
     * Remover un rol de un jugador.
     *
     * @param int $playerId ID del jugador
     * @param string|null $role Rol a remover (null = remover todos)
     * @return void
     */
    public function removeRole(int $playerId, ?string $role = null): void
    {
        if (!isset($this->playerRoles[$playerId])) {
            return;
        }

        if ($role === null) {
            // Remover todos los roles
            unset($this->playerRoles[$playerId]);
            return;
        }

        if ($this->allowMultipleRoles) {
            // Remover rol específico del array
            $this->playerRoles[$playerId] = array_values(
                array_filter(
                    $this->playerRoles[$playerId],
                    fn($r) => $r !== $role
                )
            );

            // Si no quedan roles, limpiar entrada
            if (empty($this->playerRoles[$playerId])) {
                unset($this->playerRoles[$playerId]);
            }
        } else {
            // Un solo rol: remover si coincide
            if ($this->playerRoles[$playerId] === $role) {
                unset($this->playerRoles[$playerId]);
            }
        }
    }

    /**
     * Obtener el rol de un jugador.
     *
     * @param int $playerId ID del jugador
     * @return string|array|null Rol(es) del jugador, o null si no tiene
     */
    public function getPlayerRole(int $playerId): string|array|null
    {
        return $this->playerRoles[$playerId] ?? null;
    }

    /**
     * Verificar si un jugador tiene un rol específico.
     *
     * @param int $playerId ID del jugador
     * @param string $role Rol a verificar
     * @return bool True si el jugador tiene ese rol
     */
    public function hasRole(int $playerId, string $role): bool
    {
        if (!isset($this->playerRoles[$playerId])) {
            return false;
        }

        if ($this->allowMultipleRoles) {
            return in_array($role, $this->playerRoles[$playerId]);
        }

        return $this->playerRoles[$playerId] === $role;
    }

    /**
     * Obtener todos los jugadores que tienen un rol específico.
     *
     * @param string $role Rol a buscar
     * @return array<int> IDs de jugadores con ese rol
     */
    public function getPlayersWithRole(string $role): array
    {
        $players = [];

        foreach ($this->playerRoles as $playerId => $playerRole) {
            if ($this->allowMultipleRoles) {
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
     * Obtener todos los jugadores con sus roles.
     *
     * @return array<int, string|array<string>> Mapeo de playerId => rol(es)
     */
    public function getAllPlayerRoles(): array
    {
        return $this->playerRoles;
    }

    /**
     * Obtener jugadores sin rol asignado.
     *
     * @param array<int> $allPlayerIds Todos los IDs de jugadores en el juego
     * @return array<int> IDs de jugadores sin rol
     */
    public function getPlayersWithoutRole(array $allPlayerIds): array
    {
        return array_values(array_diff($allPlayerIds, array_keys($this->playerRoles)));
    }

    /**
     * Rotar un rol al siguiente jugador (útil para turnos).
     *
     * Ejemplo: En Pictionary, rotar "drawer" al siguiente jugador en la lista.
     *
     * @param string $role Rol a rotar
     * @param array<int> $playerOrder Orden de jugadores
     * @return int|null ID del nuevo jugador con ese rol (null si no se pudo rotar)
     */
    public function rotateRole(string $role, array $playerOrder): ?int
    {
        $currentPlayers = $this->getPlayersWithRole($role);

        if (empty($currentPlayers)) {
            // Nadie tiene el rol, asignar al primero de la lista
            if (!empty($playerOrder)) {
                $this->assignRole($playerOrder[0], $role);
                return $playerOrder[0];
            }
            return null;
        }

        // Encontrar el jugador actual con el rol
        $currentPlayerId = $currentPlayers[0]; // Si hay múltiples, tomar el primero

        // Encontrar índice en el orden
        $currentIndex = array_search($currentPlayerId, $playerOrder);

        if ($currentIndex === false) {
            return null; // Jugador no está en la lista
        }

        // Siguiente jugador (circular)
        $nextIndex = ($currentIndex + 1) % count($playerOrder);
        $nextPlayerId = $playerOrder[$nextIndex];

        // Remover rol del jugador actual y asignar al siguiente
        $this->removeRole($currentPlayerId, $role);
        $this->assignRole($nextPlayerId, $role);

        return $nextPlayerId;
    }

    /**
     * Limpiar todos los roles.
     *
     * @return void
     */
    public function clearAllRoles(): void
    {
        $this->playerRoles = [];
    }

    /**
     * Obtener roles disponibles en el juego.
     *
     * @return array<string>
     */
    public function getAvailableRoles(): array
    {
        return $this->availableRoles;
    }

    /**
     * Verificar si permite múltiples roles.
     *
     * @return bool
     */
    public function allowsMultipleRoles(): bool
    {
        return $this->allowMultipleRoles;
    }

    /**
     * Serializar a array para guardar en game_state.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'player_roles' => $this->playerRoles,
            'available_roles' => $this->availableRoles,
            'allow_multiple_roles' => $this->allowMultipleRoles,
        ];
    }

    /**
     * Restaurar desde array serializado.
     *
     * @param array $data Estado serializado
     * @return self Nueva instancia restaurada
     */
    public static function fromArray(array $data): self
    {
        return new self(
            availableRoles: $data['available_roles'] ?? [],
            allowMultipleRoles: $data['allow_multiple_roles'] ?? false,
            playerRoles: $data['player_roles'] ?? []
        );
    }
}
