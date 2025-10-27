<?php

namespace App\Services\Modules\PlayerSystem;

/**
 * Datos de un jugador individual.
 *
 * Almacena toda la información de un jugador:
 * - Score (puntuación acumulada)
 * - Roles persistentes
 * - Rol de ronda actual
 * - Estado de bloqueo
 * - Acción registrada
 * - Estados custom
 * - Intentos
 */
class PlayerData
{
    public int $playerId;

    // Score
    public int $score = 0;

    // Roles
    public array $persistentRoles = [];
    public ?string $roundRole = null;

    // Bloqueo
    public bool $locked = false;
    public array $lockMetadata = [];

    // Acción
    public ?array $action = null;

    // Estados custom
    public array $customStates = [];

    // Intentos
    public int $attempts = 0;

    public function __construct(int $playerId)
    {
        $this->playerId = $playerId;
    }

    /**
     * Serializar a array.
     */
    public function toArray(): array
    {
        return [
            'player_id' => $this->playerId,
            'score' => $this->score,
            'persistent_roles' => $this->persistentRoles,
            'round_role' => $this->roundRole,
            'locked' => $this->locked,
            'lock_metadata' => $this->lockMetadata,
            'action' => $this->action,
            'custom_states' => $this->customStates,
            'attempts' => $this->attempts,
        ];
    }

    /**
     * Restaurar desde array.
     */
    public static function fromArray(array $data): self
    {
        $instance = new self($data['player_id']);
        $instance->score = $data['score'] ?? 0;
        $instance->persistentRoles = $data['persistent_roles'] ?? [];
        $instance->roundRole = $data['round_role'] ?? null;
        $instance->locked = $data['locked'] ?? false;
        $instance->lockMetadata = $data['lock_metadata'] ?? [];
        $instance->action = $data['action'] ?? null;
        $instance->customStates = $data['custom_states'] ?? [];
        $instance->attempts = $data['attempts'] ?? 0;

        return $instance;
    }
}
