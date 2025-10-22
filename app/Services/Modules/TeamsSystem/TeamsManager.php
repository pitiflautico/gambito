<?php

namespace App\Services\Modules\TeamsSystem;

use App\Models\GameMatch;
use Illuminate\Support\Facades\Cache;

/**
 * TeamsManager - Gestión de Equipos con Redis
 *
 * Gestiona equipos de juego de forma efímera usando Redis como almacenamiento principal
 * y sincronizando periódicamente con la base de datos para persistencia.
 *
 * @package App\Services\Modules\TeamsSystem
 */
class TeamsManager
{
    protected GameMatch $match;
    protected string $cacheKey;
    protected string $lockKey;
    protected int $syncInterval = 10; // Sincronizar a BD cada 10 segundos
    protected int $lockTimeout = 5; // Timeout del lock en segundos

    /**
     * Colores por defecto para equipos
     */
    protected array $defaultColors = [
        '#EF4444', // Rojo
        '#3B82F6', // Azul
        '#10B981', // Verde
        '#F59E0B', // Amarillo
        '#8B5CF6', // Púrpura
        '#EC4899', // Rosa
        '#06B6D4', // Cyan
        '#F97316', // Naranja
    ];

    /**
     * Constructor
     */
    public function __construct(GameMatch $match)
    {
        $this->match = $match;
        $this->cacheKey = "game:match:{$match->id}:state";
        $this->lockKey = "game:match:{$match->id}:lock";

        $this->loadOrInitialize();
    }

    /**
     * Cargar estado desde Redis o inicializar desde BD
     */
    protected function loadOrInitialize(): void
    {
        if (!Cache::has($this->cacheKey)) {
            // No está en Redis, cargar desde BD
            $state = $this->match->game_state ?? $this->getInitialState();
            Cache::put($this->cacheKey, $state, now()->addHours(24));
        }
    }

    /**
     * Obtener estado inicial del juego
     */
    protected function getInitialState(): array
    {
        return [
            'teams_config' => [
                'enabled' => false,
                'mode' => 'all_teams',
                'allow_self_selection' => false,
                'max_members_per_team' => null,
                'current_team_index' => 0,
                'teams' => []
            ]
        ];
    }

    // ==================== CONFIGURACIÓN ====================

    /**
     * Verificar si el modo equipos está habilitado
     */
    public function isEnabled(): bool
    {
        $state = $this->getState();
        return $state['teams_config']['enabled'] ?? false;
    }

    /**
     * Obtener modo de juego de equipos
     */
    public function getMode(): string
    {
        $state = $this->getState();
        return $state['teams_config']['mode'] ?? 'all_teams';
    }

    /**
     * Habilitar modo equipos
     */
    public function enableTeams(string $mode, int $numTeams = 2): void
    {
        Cache::lock($this->lockKey, $this->lockTimeout)->block(3, function() use ($mode, $numTeams) {
            $state = $this->getState();

            $state['teams_config']['enabled'] = true;
            $state['teams_config']['mode'] = $mode;

            // Crear equipos iniciales si no existen
            if (empty($state['teams_config']['teams'])) {
                for ($i = 0; $i < $numTeams; $i++) {
                    $state['teams_config']['teams'][] = $this->createTeamArray(
                        "Equipo " . ($i + 1),
                        $this->defaultColors[$i % count($this->defaultColors)],
                        $i
                    );
                }
            }

            $this->setState($state);
            $this->save(true); // Forzar sync a BD
        });
    }

    /**
     * Deshabilitar modo equipos
     */
    public function disableTeams(): void
    {
        Cache::lock($this->lockKey, $this->lockTimeout)->block(3, function() {
            $state = $this->getState();

            $state['teams_config']['enabled'] = false;
            $state['teams_config']['teams'] = [];

            $this->setState($state);
            $this->save(true);
        });
    }

    /**
     * Establecer si los jugadores pueden elegir equipo
     */
    public function setAllowSelfSelection(bool $allow): void
    {
        Cache::lock($this->lockKey, $this->lockTimeout)->block(3, function() use ($allow) {
            $state = $this->getState();
            $state['teams_config']['allow_self_selection'] = $allow;
            $this->setState($state);
            $this->save();
        });
    }

    /**
     * Verificar si la autoselección está habilitada
     */
    public function getAllowSelfSelection(): bool
    {
        $state = $this->getState();
        return $state['teams_config']['allow_self_selection'] ?? false;
    }

    /**
     * Establecer máximo de miembros por equipo
     */
    public function setMaxMembersPerTeam(?int $max): void
    {
        Cache::lock($this->lockKey, $this->lockTimeout)->block(3, function() use ($max) {
            $state = $this->getState();
            $state['teams_config']['max_members_per_team'] = $max;
            $this->setState($state);
            $this->save();
        });
    }

    // ==================== GESTIÓN DE EQUIPOS ====================

    /**
     * Crear un nuevo equipo
     */
    public function createTeam(string $name, ?string $color = null): array
    {
        return Cache::lock($this->lockKey, $this->lockTimeout)->block(3, function() use ($name, $color) {
            $state = $this->getState();
            $position = count($state['teams_config']['teams'] ?? []);

            $team = $this->createTeamArray($name, $color, $position);

            $state['teams_config']['teams'][] = $team;
            $this->setState($state);
            $this->save();

            return $team;
        });
    }

    /**
     * Crear array de equipo
     */
    protected function createTeamArray(string $name, ?string $color, int $position): array
    {
        return [
            'id' => 'team_' . uniqid(),
            'name' => $name,
            'color' => $color ?? $this->generateRandomColor(),
            'position' => $position,
            'score' => 0,
            'members' => [],
            'stats' => [
                'correct_answers' => 0,
                'total_time' => 0,
                'rounds_won' => 0
            ]
        ];
    }

    /**
     * Eliminar un equipo
     */
    public function deleteTeam(string $teamId): bool
    {
        return Cache::lock($this->lockKey, $this->lockTimeout)->block(3, function() use ($teamId) {
            $state = $this->getState();
            $teams = $state['teams_config']['teams'] ?? [];

            $filtered = array_filter($teams, fn($team) => $team['id'] !== $teamId);

            if (count($filtered) === count($teams)) {
                return false; // No se encontró el equipo
            }

            // Reindexar posiciones
            $state['teams_config']['teams'] = array_values($filtered);
            foreach ($state['teams_config']['teams'] as $index => &$team) {
                $team['position'] = $index;
            }

            $this->setState($state);
            $this->save();

            return true;
        });
    }

    /**
     * Asignar jugador a un equipo
     */
    public function assignPlayerToTeam(int $playerId, string $teamId): bool
    {
        return Cache::lock($this->lockKey, $this->lockTimeout)->block(3, function() use ($playerId, $teamId) {
            $state = $this->getState();

            // Remover de equipo anterior si existe
            foreach ($state['teams_config']['teams'] as &$team) {
                $key = array_search($playerId, $team['members']);
                if ($key !== false) {
                    array_splice($team['members'], $key, 1);
                    $team['members'] = array_values($team['members']); // Reindexar
                }
            }

            // Agregar al nuevo equipo
            foreach ($state['teams_config']['teams'] as &$team) {
                if ($team['id'] === $teamId) {
                    // Verificar límite de miembros
                    $maxMembers = $state['teams_config']['max_members_per_team'];
                    if ($maxMembers !== null && count($team['members']) >= $maxMembers) {
                        return false; // Equipo lleno
                    }

                    if (!in_array($playerId, $team['members'])) {
                        $team['members'][] = $playerId;
                    }
                    $this->setState($state);
                    $this->save();
                    return true;
                }
            }

            return false; // No se encontró el equipo
        });
    }

    /**
     * Remover jugador de su equipo
     */
    public function removePlayerFromTeam(int $playerId): bool
    {
        return Cache::lock($this->lockKey, $this->lockTimeout)->block(3, function() use ($playerId) {
            $state = $this->getState();
            $found = false;

            foreach ($state['teams_config']['teams'] as &$team) {
                $key = array_search($playerId, $team['members']);
                if ($key !== false) {
                    array_splice($team['members'], $key, 1);
                    $team['members'] = array_values($team['members']);
                    $found = true;
                    break;
                }
            }

            if ($found) {
                $this->setState($state);
                $this->save();
            }

            return $found;
        });
    }

    /**
     * Balancear equipos distribuyendo jugadores equitativamente
     */
    public function balanceTeams(array $playerIds): void
    {
        Cache::lock($this->lockKey, $this->lockTimeout)->block(3, function() use ($playerIds) {
            $state = $this->getState();
            $teams = &$state['teams_config']['teams'];

            if (empty($teams)) {
                return;
            }

            // Limpiar equipos
            foreach ($teams as &$team) {
                $team['members'] = [];
            }

            // Distribuir jugadores
            shuffle($playerIds); // Aleatorizar orden

            $teamCount = count($teams);
            foreach ($playerIds as $index => $playerId) {
                $teamIndex = $index % $teamCount;
                $teams[$teamIndex]['members'][] = $playerId;
            }

            $this->setState($state);
            $this->save();
        });
    }

    // ==================== CONSULTAS ====================

    /**
     * Obtener todos los equipos
     */
    public function getTeams(): array
    {
        $state = $this->getState();
        return $state['teams_config']['teams'] ?? [];
    }

    /**
     * Obtener un equipo por ID
     */
    public function getTeam(string $teamId): ?array
    {
        $teams = $this->getTeams();

        foreach ($teams as $team) {
            if ($team['id'] === $teamId) {
                return $team;
            }
        }

        return null;
    }

    /**
     * Obtener miembros de un equipo
     */
    public function getTeamMembers(string $teamId): array
    {
        $team = $this->getTeam($teamId);
        return $team['members'] ?? [];
    }

    /**
     * Obtener el equipo de un jugador
     */
    public function getPlayerTeam(int $playerId): ?array
    {
        $teams = $this->getTeams();

        foreach ($teams as $team) {
            if (in_array($playerId, $team['members'])) {
                return $team;
            }
        }

        return null;
    }

    /**
     * Obtener puntuación de un equipo
     */
    public function getTeamScore(string $teamId): int
    {
        $team = $this->getTeam($teamId);
        return $team['score'] ?? 0;
    }

    // ==================== TURNOS ====================

    /**
     * Obtener equipo actual (con el turno)
     */
    public function getCurrentTeam(): ?array
    {
        $state = $this->getState();
        $teams = $state['teams_config']['teams'] ?? [];
        $currentIndex = $state['teams_config']['current_team_index'] ?? 0;

        return $teams[$currentIndex] ?? null;
    }

    /**
     * Obtener ID del equipo actual
     */
    public function getCurrentTeamId(): ?string
    {
        $team = $this->getCurrentTeam();
        return $team['id'] ?? null;
    }

    /**
     * Establecer equipo actual por ID
     */
    public function setCurrentTeam(string $teamId): void
    {
        Cache::lock($this->lockKey, $this->lockTimeout)->block(3, function() use ($teamId) {
            $state = $this->getState();
            $teams = $state['teams_config']['teams'] ?? [];

            foreach ($teams as $index => $team) {
                if ($team['id'] === $teamId) {
                    $state['teams_config']['current_team_index'] = $index;
                    $this->setState($state);
                    $this->save();
                    return;
                }
            }
        });
    }

    /**
     * Avanzar al siguiente equipo
     */
    public function nextTeam(): array
    {
        return Cache::lock($this->lockKey, $this->lockTimeout)->block(3, function() {
            $state = $this->getState();
            $teams = $state['teams_config']['teams'] ?? [];

            if (empty($teams)) {
                return [];
            }

            $currentIndex = $state['teams_config']['current_team_index'] ?? 0;
            $nextIndex = ($currentIndex + 1) % count($teams);

            $state['teams_config']['current_team_index'] = $nextIndex;
            $this->setState($state);
            $this->save();

            return $teams[$nextIndex];
        });
    }

    // ==================== PUNTUACIÓN ====================

    /**
     * Agregar puntos a un equipo
     */
    public function addTeamScore(string $teamId, int $points): void
    {
        Cache::lock($this->lockKey, $this->lockTimeout)->block(3, function() use ($teamId, $points) {
            $state = $this->getState();

            foreach ($state['teams_config']['teams'] as &$team) {
                if ($team['id'] === $teamId) {
                    $team['score'] += $points;
                    $this->setState($state);
                    $this->save();
                    return;
                }
            }
        });
    }

    /**
     * Obtener ranking de equipos ordenado por puntuación
     */
    public function getTeamRanking(): array
    {
        $teams = $this->getTeams();

        usort($teams, fn($a, $b) => $b['score'] <=> $a['score']);

        // Agregar posición
        foreach ($teams as $index => &$team) {
            $team['rank'] = $index + 1;
        }

        return $teams;
    }

    /**
     * Obtener equipo ganador
     */
    public function getWinningTeam(): ?array
    {
        $ranking = $this->getTeamRanking();
        return $ranking[0] ?? null;
    }

    // ==================== VALIDACIONES ====================

    /**
     * Validar que los equipos estén listos para iniciar
     */
    public function validateTeamsForStart(): array
    {
        $errors = [];
        $teams = $this->getTeams();

        if (count($teams) < 2) {
            $errors[] = 'Se necesitan al menos 2 equipos';
        }

        // Verificar equipos vacíos
        foreach ($teams as $team) {
            if (empty($team['members'])) {
                $errors[] = "El equipo '{$team['name']}' está vacío";
            }
        }

        // Verificar balance
        $memberCounts = array_map(fn($team) => count($team['members']), $teams);
        $maxDiff = max($memberCounts) - min($memberCounts);

        if ($maxDiff > 2) {
            $errors[] = "Los equipos están muy desbalanceados (diferencia de {$maxDiff} jugadores)";
        }

        return $errors;
    }

    /**
     * Verificar si todos los equipos están listos
     */
    public function allTeamsReady(): bool
    {
        return empty($this->validateTeamsForStart());
    }

    // ==================== PERSISTENCIA ====================

    /**
     * Guardar estado en Redis y opcionalmente en BD
     */
    protected function save(bool $syncToDB = false): void
    {
        $lastSyncKey = "game:match:{$this->match->id}:last_sync";
        $lastSync = Cache::get($lastSyncKey, 0);

        if ($syncToDB || (time() - $lastSync) >= $this->syncInterval) {
            $this->syncToDatabase();
            Cache::put($lastSyncKey, time(), now()->addHours(24));
        }
    }

    /**
     * Sincronizar estado a la base de datos
     */
    public function syncToDatabase(): void
    {
        $state = $this->getState();
        $this->match->game_state = $state;
        $this->match->saveQuietly(); // Sin eventos para evitar overhead
    }

    /**
     * Obtener estado desde Redis
     */
    protected function getState(): array
    {
        return Cache::get($this->cacheKey, $this->getInitialState());
    }

    /**
     * Establecer estado en Redis
     */
    protected function setState(array $state): void
    {
        Cache::put($this->cacheKey, $state, now()->addHours(24));
    }

    /**
     * Generar color aleatorio
     */
    protected function generateRandomColor(): string
    {
        return $this->defaultColors[array_rand($this->defaultColors)];
    }
}
