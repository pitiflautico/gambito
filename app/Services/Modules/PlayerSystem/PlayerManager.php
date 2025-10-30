<?php

namespace App\Services\Modules\PlayerSystem;

use App\Models\GameMatch;
use App\Models\Player;

/**
 * Gestor unificado de jugadores (scores + estado).
 *
 * Este módulo centraliza TODA la información de jugadores:
 * - Scores (puntuación acumulada)
 * - Roles persistentes (todo el juego)
 * - Roles de ronda (temporales)
 * - Bloqueos (¿puede actuar?)
 * - Acciones (¿qué hizo?)
 * - Estados custom
 * - Intentos/Vidas
 *
 * EVENTOS:
 * - PlayerScoreUpdatedEvent: Cuando cambia el score de un jugador
 * - PlayersUnlockedEvent: Cuando se desbloquean todos
 *
 * SERIALIZACIÓN:
 * - toArray(): Para guardar en game_state
 * - fromArray(): Para restaurar desde game_state
 */
class PlayerManager
{
    /**
     * Información de cada jugador.
     *
     * @var array<int, PlayerData>
     */
    protected array $players = [];

    /**
     * Calculador de puntos del juego (Strategy pattern).
     *
     * @var ScoreCalculatorInterface|null
     */
    protected ?object $scoreCalculator = null;

    /**
     * Roles disponibles en el juego.
     *
     * @var array<string>
     */
    protected array $availableRoles = [];

    /**
     * Si permite múltiples roles persistentes por jugador.
     *
     * @var bool
     */
    protected bool $allowMultiplePersistentRoles;

    /**
     * Si se registra historial de scores.
     *
     * @var bool
     */
    protected bool $trackScoreHistory;

    /**
     * Historial de cambios de score.
     *
     * @var array
     */
    protected array $scoreHistory = [];

    /**
     * Constructor.
     *
     * @param array $playerIds Lista de IDs de jugadores
     * @param object|null $scoreCalculator Calculador de puntos (opcional)
     * @param array $config Configuración adicional
     */
    public function __construct(
        array $playerIds,
        ?object $scoreCalculator = null,
        array $config = []
    ) {
        if (empty($playerIds)) {
            throw new \InvalidArgumentException('Se requiere al menos un jugador');
        }

        $this->scoreCalculator = $scoreCalculator;
        $this->availableRoles = $config['available_roles'] ?? [];
        $this->allowMultiplePersistentRoles = $config['allow_multiple_persistent_roles'] ?? false;
        $this->trackScoreHistory = $config['track_score_history'] ?? false;

        // Inicializar todos los jugadores
        foreach ($playerIds as $playerId) {
            $this->players[$playerId] = new PlayerData($playerId);
        }
    }

    // ========================================================================
    // SCORES
    // ========================================================================

    /**
     * Obtener score de un jugador.
     */
    public function getScore(int $playerId): int
    {
        $this->validatePlayer($playerId);
        return $this->players[$playerId]->score;
    }

    /**
     * Obtener scores de todos los jugadores.
     *
     * @return array<int, int> [player_id => score]
     */
    public function getScores(): array
    {
        $scores = [];
        foreach ($this->players as $playerId => $player) {
            $scores[$playerId] = $player->score;
        }
        return $scores;
    }

    /**
     * Establecer score de un jugador directamente.
     */
    public function setScore(int $playerId, int $score, ?GameMatch $match = null): void
    {
        $this->validatePlayer($playerId);

        $oldScore = $this->players[$playerId]->score;
        $this->players[$playerId]->score = $score;

        // Registrar en historial
        if ($this->trackScoreHistory) {
            $this->scoreHistory[] = [
                'player_id' => $playerId,
                'old_score' => $oldScore,
                'new_score' => $score,
                'timestamp' => now()->toDateTimeString(),
            ];
        }

        // Emitir evento si hay match
        if ($match) {
            event(new \App\Events\Game\PlayerScoreUpdatedEvent(
                $match,
                $playerId,
                $score,
                $score - $oldScore
            ));
        }
    }

    /**
     * Otorgar puntos a un jugador por un evento.
     *
     * @param int $playerId ID del jugador
     * @param string $eventType Tipo de evento (ej: 'correct_answer')
     * @param array $context Contexto del evento
     * @param GameMatch|null $match Para emitir evento
     * @return int Puntos otorgados
     */
    public function awardPoints(
        int $playerId,
        string $eventType,
        array $context = [],
        ?GameMatch $match = null
    ): int {
        $this->validatePlayer($playerId);

        if (!$this->scoreCalculator) {
            throw new \RuntimeException('ScoreCalculator no configurado');
        }

        // Calcular puntos usando el calculator del juego
        $points = $this->scoreCalculator->calculate($eventType, $context);
        $oldScore = $this->players[$playerId]->score;
        $this->players[$playerId]->score += $points;

        // Registrar en historial
        if ($this->trackScoreHistory) {
            $this->scoreHistory[] = [
                'player_id' => $playerId,
                'event_type' => $eventType,
                'points' => $points,
                'old_score' => $oldScore,
                'new_score' => $this->players[$playerId]->score,
                'context' => $context,
                'timestamp' => now()->toDateTimeString(),
            ];
        }

        // Emitir evento si hay match
        if ($match) {
            event(new \App\Events\Game\PlayerScoreUpdatedEvent(
                $match,
                $playerId,
                $this->players[$playerId]->score,
                $points
            ));
        }

        return $points;
    }

    /**
     * Obtener ranking ordenado por score.
     *
     * @return array Array de [player_id, score, rank]
     */
    public function getRanking(): array
    {
        $scores = $this->getScores();
        arsort($scores);

        $ranking = [];
        $rank = 1;
        $previousScore = null;
        $sameRankCount = 0;

        foreach ($scores as $playerId => $score) {
            if ($previousScore !== null && $score < $previousScore) {
                $rank += $sameRankCount;
                $sameRankCount = 1;
            } else {
                $sameRankCount++;
            }

            $ranking[] = [
                'player_id' => $playerId,
                'score' => $score,
                'rank' => $rank,
            ];

            $previousScore = $score;
        }

        return $ranking;
    }

    // ========================================================================
    // ROLES PERSISTENTES
    // ========================================================================

    /**
     * Asignar rol persistente a un jugador.
     */
    public function assignPersistentRole(int $playerId, string $role): void
    {
        $this->validatePlayer($playerId);
        $this->validateRole($role);

        if ($this->allowMultiplePersistentRoles) {
            if (!in_array($role, $this->players[$playerId]->persistentRoles)) {
                $this->players[$playerId]->persistentRoles[] = $role;
            }
        } else {
            $this->players[$playerId]->persistentRoles = [$role];
        }
    }

    /**
     * Verificar si un jugador tiene un rol persistente.
     */
    public function hasPersistentRole(int $playerId, string $role): bool
    {
        $this->validatePlayer($playerId);
        return in_array($role, $this->players[$playerId]->persistentRoles);
    }

    /**
     * Obtener roles persistentes de un jugador.
     *
     * @return array<string>
     */
    public function getPersistentRoles(int $playerId): array
    {
        $this->validatePlayer($playerId);
        return $this->players[$playerId]->persistentRoles;
    }

    /**
     * Obtener jugadores con un rol persistente específico.
     *
     * @return array<int> Lista de player_ids
     */
    public function getPlayersWithPersistentRole(string $role): array
    {
        $players = [];
        foreach ($this->players as $playerId => $player) {
            if (in_array($role, $player->persistentRoles)) {
                $players[] = $playerId;
            }
        }
        return $players;
    }

    // ========================================================================
    // ROLES DE RONDA
    // ========================================================================

    /**
     * Asignar rol de ronda a un jugador.
     */
    public function assignRoundRole(int $playerId, string $role): void
    {
        $this->validatePlayer($playerId);
        $this->players[$playerId]->roundRole = $role;
    }

    /**
     * Remover rol de ronda de un jugador.
     */
    public function removeRoundRole(int $playerId): void
    {
        $this->validatePlayer($playerId);
        $this->players[$playerId]->roundRole = null;
    }

    /**
     * Obtener rol de ronda de un jugador.
     */
    public function getRoundRole(int $playerId): ?string
    {
        $this->validatePlayer($playerId);
        return $this->players[$playerId]->roundRole;
    }

    /**
     * Verificar si un jugador tiene un rol de ronda.
     */
    public function hasRoundRole(int $playerId, string $role): bool
    {
        $this->validatePlayer($playerId);
        return $this->players[$playerId]->roundRole === $role;
    }

    /**
     * Obtener jugadores con un rol de ronda específico.
     *
     * @return array<int> Lista de player_ids
     */
    public function getPlayersWithRoundRole(string $role): array
    {
        $players = [];
        foreach ($this->players as $playerId => $player) {
            if ($player->roundRole === $role) {
                $players[] = $playerId;
            }
        }
        return $players;
    }

    /**
     * Verificar si todos los jugadores con un rol específico están bloqueados y ninguno acertó.
     *
     * Útil para juegos donde los jugadores tienen un solo intento (como Pictionary).
     * Retorna true si:
     * - Todos los jugadores con ese rol están bloqueados
     * - Ninguno tiene una acción marcada como correcta
     * - Hay al menos un jugador con ese rol
     *
     * @param string $role El rol a verificar (ej: 'guesser')
     * @return bool True si todos intentaron y todos fallaron
     */
    public function haveAllRolePlayersFailedAttempt(string $role): bool
    {
        $playersWithRole = $this->getPlayersWithRoundRole($role);

        // Si no hay jugadores con ese rol, retornar false
        if (empty($playersWithRole)) {
            return false;
        }

        $allLocked = true;
        $anyCorrect = false;

        foreach ($playersWithRole as $playerId) {
            // Si algún jugador NO está bloqueado, aún pueden intentar
            if (!$this->isPlayerLocked($playerId)) {
                $allLocked = false;
                break;
            }

            // Verificar si alguno tiene acción correcta
            $action = $this->getPlayerAction($playerId);
            if ($action && ($action['is_correct'] ?? false)) {
                $anyCorrect = true;
            }
        }

        // Todos intentaron (bloqueados) Y ninguno acertó
        return $allLocked && !$anyCorrect;
    }

    // ========================================================================
    // BLOQUEOS
    // ========================================================================

    /**
     * Bloquear jugador.
     */
    public function lockPlayer(
        int $playerId,
        ?GameMatch $match = null,
        ?Player $player = null,
        array $metadata = []
    ): array {
        $this->validatePlayer($playerId);

        if ($this->players[$playerId]->locked) {
            return [
                'success' => false,
                'message' => 'Jugador ya está bloqueado',
            ];
        }

        $this->players[$playerId]->locked = true;
        $this->players[$playerId]->lockMetadata = $metadata;

        // Emitir evento PlayerLockedEvent si hay match
        if ($match && $player) {
            event(new \App\Events\Game\PlayerLockedEvent($match, $player, $metadata));
        }

        return [
            'success' => true,
            'message' => 'Jugador bloqueado',
        ];
    }

    /**
     * Desbloquear jugador.
     */
    public function unlockPlayer(int $playerId): void
    {
        $this->validatePlayer($playerId);
        $this->players[$playerId]->locked = false;
        $this->players[$playerId]->lockMetadata = [];
    }

    /**
     * Verificar si un jugador está bloqueado.
     */
    public function isPlayerLocked(int $playerId): bool
    {
        $this->validatePlayer($playerId);
        return $this->players[$playerId]->locked;
    }

    /**
     * Obtener jugadores bloqueados.
     *
     * @return array<int> Lista de player_ids
     */
    public function getLockedPlayers(): array
    {
        $locked = [];
        foreach ($this->players as $playerId => $player) {
            if ($player->locked) {
                $locked[] = $playerId;
            }
        }
        return $locked;
    }

    /**
     * Verificar si todos los jugadores están bloqueados.
     *
     * @return bool True si todos los jugadores están bloqueados
     */
    public function areAllPlayersLocked(): bool
    {
        foreach ($this->players as $player) {
            if (!$player->locked) {
                return false;
            }
        }
        return true;
    }

    // ========================================================================
    // ACCIONES
    // ========================================================================

    /**
     * Registrar acción de un jugador.
     */
    public function setPlayerAction(int $playerId, array $action): void
    {
        $this->validatePlayer($playerId);
        $this->players[$playerId]->action = $action;
    }

    /**
     * Obtener acción de un jugador.
     */
    public function getPlayerAction(int $playerId): ?array
    {
        $this->validatePlayer($playerId);
        return $this->players[$playerId]->action;
    }

    /**
     * Obtener acciones de todos los jugadores.
     *
     * @return array<int, array> [player_id => action]
     */
    public function getAllActions(): array
    {
        $actions = [];
        foreach ($this->players as $playerId => $player) {
            if ($player->action !== null) {
                $actions[$playerId] = $player->action;
            }
        }
        return $actions;
    }

    // ========================================================================
    // ESTADOS CUSTOM
    // ========================================================================

    /**
     * Establecer estado custom de un jugador.
     */
    public function setPlayerState(int $playerId, string $key, $value): void
    {
        $this->validatePlayer($playerId);
        $this->players[$playerId]->customStates[$key] = $value;
    }

    /**
     * Obtener estado custom de un jugador.
     */
    public function getPlayerState(int $playerId, string $key, $default = null)
    {
        $this->validatePlayer($playerId);
        return $this->players[$playerId]->customStates[$key] ?? $default;
    }

    // ========================================================================
    // INTENTOS
    // ========================================================================

    /**
     * Incrementar intentos de un jugador.
     */
    public function incrementAttempts(int $playerId): int
    {
        $this->validatePlayer($playerId);
        return ++$this->players[$playerId]->attempts;
    }

    /**
     * Obtener intentos de un jugador.
     */
    public function getAttempts(int $playerId): int
    {
        $this->validatePlayer($playerId);
        return $this->players[$playerId]->attempts;
    }

    // ========================================================================
    // RESET
    // ========================================================================

    /**
     * Resetear estado temporal (al iniciar nueva ronda).
     *
     * Limpia:
     * - Bloqueos
     * - Acciones
     * - Estados custom
     * - Intentos
     *
     * NO limpia:
     * - Scores
     * - Roles persistentes
     * - Roles de ronda (se actualizan explícitamente)
     */
    public function reset(?GameMatch $match = null, array $additionalData = []): void
    {
        $hadLockedPlayers = false;

        foreach ($this->players as $playerId => $player) {
            if ($player->locked) {
                $hadLockedPlayers = true;
            }

            $player->locked = false;
            $player->lockMetadata = [];
            $player->action = null;
            $player->customStates = [];
            $player->attempts = 0;
            // NO reseteamos roundRole - se actualiza explícitamente por el juego
        }

        // Emitir evento de desbloqueo si había jugadores bloqueados
        if ($hadLockedPlayers && $match) {
            event(new \App\Events\Game\PlayersUnlockedEvent(
                $match,
                $additionalData
            ));

            \Log::info("[PlayerManager] State reset and players unlocked event emitted", [
                'match_id' => $match->id,
            ]);
        }
    }

    // ========================================================================
    // UTILIDADES
    // ========================================================================

    /**
     * Obtener lista de IDs de jugadores.
     *
     * @return array<int>
     */
    public function getPlayerIds(): array
    {
        return array_keys($this->players);
    }

    /**
     * Validar que un jugador existe.
     */
    protected function validatePlayer(int $playerId): void
    {
        if (!isset($this->players[$playerId])) {
            throw new \InvalidArgumentException("Jugador {$playerId} no existe");
        }
    }

    /**
     * Validar que un rol existe.
     */
    protected function validateRole(string $role): void
    {
        if (!empty($this->availableRoles) && !in_array($role, $this->availableRoles)) {
            throw new \InvalidArgumentException("Rol '{$role}' no está disponible");
        }
    }

    // ========================================================================
    // SERIALIZACIÓN
    // ========================================================================

    /**
     * Serializar a array para guardar en game_state.
     */
    public function toArray(): array
    {
        $data = [
            'player_system' => [
                'players' => [],
                'config' => [
                    'available_roles' => $this->availableRoles,
                    'allow_multiple_persistent_roles' => $this->allowMultiplePersistentRoles,
                    'track_score_history' => $this->trackScoreHistory,
                ],
            ],
        ];

        foreach ($this->players as $playerId => $player) {
            $data['player_system']['players'][$playerId] = $player->toArray();
        }

        if ($this->trackScoreHistory) {
            $data['player_system']['score_history'] = $this->scoreHistory;
        }

        return $data;
    }

    /**
     * Restaurar desde array serializado.
     */
    public static function fromArray(array $data, ?object $scoreCalculator = null): self
    {
        $playerData = $data['player_system'] ?? [];
        $config = $playerData['config'] ?? [];

        $players = $playerData['players'] ?? [];
        $playerIds = array_keys($players);

        $instance = new self($playerIds, $scoreCalculator, $config);

        // Restaurar datos de cada jugador
        foreach ($players as $playerId => $playerArray) {
            $instance->players[$playerId] = PlayerData::fromArray($playerArray);
        }

        // Restaurar historial
        if (isset($playerData['score_history'])) {
            $instance->scoreHistory = $playerData['score_history'];
        }

        return $instance;
    }
}
