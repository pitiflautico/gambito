# Sistema de Equipos - Diseño Técnico

## 🎯 Objetivo

Permitir que los juegos se jueguen por equipos, donde múltiples jugadores colaboran en un mismo equipo compitiendo contra otros equipos.

## 📊 Modelo de Datos

**❌ SIN TABLAS EN BASE DE DATOS**

Los equipos son **efímeros** y se gestionan completamente en memoria/JSON. Se almacenan en:
- `matches.game_state` mientras la partida está activa
- Se eliminan automáticamente cuando la partida termina
- No necesitan persistencia más allá de la duración de la partida

### Estructura: `matches.game_state`

Los equipos se almacenan en el JSON `game_state`:

```json
{
  "teams_config": {
    "enabled": false,                    // ¿Se juega por equipos?
    "mode": "all_teams",                 // Modo: "team_turns" | "all_teams" | "sequential_within_team"
    "allow_self_selection": false,       // ¿Los jugadores pueden elegir equipo?
    "max_members_per_team": null,        // null = sin límite, número = límite
    "current_team_index": 0,             // Índice del equipo con el turno actual

    "teams": [
      {
        "id": "team_1",            // ID único (string para simplificar JSON)
        "name": "Equipo Rojo",
        "color": "#EF4444",        // Color hex
        "position": 0,             // Orden de turnos
        "score": 0,
        "members": [1, 5, 9],      // Array de player_ids
        "stats": {                  // Estadísticas del equipo
          "correct_answers": 0,
          "total_time": 0,
          "rounds_won": 0
        }
      },
      {
        "id": "team_2",
        "name": "Equipo Azul",
        "color": "#3B82F6",
        "position": 1,
        "score": 0,
        "members": [2, 6, 10],
        "stats": {
          "correct_answers": 0,
          "total_time": 0,
          "rounds_won": 0
        }
      }
    ]
  }
}
```

## 🚀 Arquitectura de 3 Capas (Alta Performance)

### Capa 1: Redis (Estado Activo en Memoria)

**Para qué**: Partidas activas, múltiples salas simultáneas, cambios frecuentes

```
Redis Keys:
- game:match:{match_id}:state         → JSON completo del game_state
- game:match:{match_id}:teams         → Hash de equipos
- game:match:{match_id}:scores        → Sorted Set de puntuaciones
- game:match:{match_id}:lock          → Lock distribuido para concurrencia
```

**TTL**: 24 horas (auto-limpieza si la partida se abandona)

### Capa 2: Database `matches.game_state` (Persistencia/Snapshot)

**Para qué**: Backup periódico, recuperación ante fallos, auditoría

- Se sincroniza cada X segundos (configurable: 5-30s)
- Se guarda al finalizar la partida
- Se usa para recuperar si Redis falla o se reinicia

### Capa 3: Database `game_events` (Historial - OPCIONAL)

**Para qué**: Replay, debugging, estadísticas avanzadas

- Solo si necesitas reconstruir la partida paso a paso
- Se puede deshabilitar en producción si no se usa
- Limpieza automática cada 7 días

### Ventajas de esta Arquitectura

✅ **Performance**: Redis maneja 100k+ ops/segundo
✅ **Escalabilidad**: Múltiples salas simultáneas sin problema
✅ **Confiabilidad**: Backup en BD cada pocos segundos
✅ **Recuperación**: Si Redis falla, se recupera desde BD
✅ **Auto-limpieza**: TTL en Redis + comando de limpieza en BD
✅ **Concurrencia**: Locks distribuidos para evitar race conditions

### Persistencia y Recuperación

#### Tabla: `game_events` (Historial Temporal)

```sql
CREATE TABLE game_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL,      -- 'team_created', 'player_moved', 'score_updated', etc.
    event_data JSON NOT NULL,              -- Datos del evento
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
    INDEX idx_match_id_created (match_id, created_at),
    INDEX idx_event_type (event_type)
);
```

#### Tipos de Eventos

```json
{
  "event_type": "team_created",
  "event_data": {
    "team_id": "team_xyz",
    "name": "Equipo Rojo",
    "color": "#EF4444",
    "created_by_player_id": 123
  }
}

{
  "event_type": "player_moved_to_team",
  "event_data": {
    "player_id": 456,
    "from_team_id": null,
    "to_team_id": "team_xyz",
    "moved_by_player_id": 123
  }
}

{
  "event_type": "team_score_updated",
  "event_data": {
    "team_id": "team_xyz",
    "points_added": 100,
    "new_total": 350,
    "reason": "correct_answer"
  }
}
```

#### Recuperación ante Fallos

```php
class GameRecoveryService
{
    /**
     * Recuperar estado del juego desde la BD
     */
    public function recoverMatch(int $matchId): GameMatch
    {
        $match = GameMatch::findOrFail($matchId);

        // El game_state ya tiene toda la información necesaria
        // incluyendo equipos, puntuaciones, turnos actuales, etc.

        return $match;
    }

    /**
     * Reconstruir estado desde eventos (para debugging/replay)
     */
    public function rebuildStateFromEvents(int $matchId): array
    {
        $events = DB::table('game_events')
            ->where('match_id', $matchId)
            ->orderBy('created_at')
            ->get();

        $state = $this->getInitialState();

        foreach ($events as $event) {
            $state = $this->applyEvent($state, $event);
        }

        return $state;
    }
}
```

#### Comando de Limpieza

```php
// app/Console/Commands/CleanupOldGameEvents.php

class CleanupOldGameEvents extends Command
{
    protected $signature = 'games:cleanup-events {--days=7}';
    protected $description = 'Eliminar eventos de partidas finalizadas hace X días';

    public function handle()
    {
        $days = $this->option('days');

        $deleted = DB::table('game_events')
            ->whereIn('match_id', function($query) use ($days) {
                $query->select('id')
                    ->from('matches')
                    ->whereNotNull('finished_at')
                    ->where('finished_at', '<', now()->subDays($days));
            })
            ->delete();

        $this->info("Eliminados {$deleted} eventos de partidas antiguas.");
    }
}

// Registrar en Kernel.php para ejecución automática
protected function schedule(Schedule $schedule)
{
    $schedule->command('games:cleanup-events --days=7')->daily();
}
```

## 🎮 Modos de Juego por Equipos

### 1. **Team Turns** (Turnos por Equipo)
- Un equipo juega su turno completo mientras otros esperan
- Todos los miembros del equipo activo participan simultáneamente
- Ejemplo: Pictionary por equipos (un equipo dibuja/adivina por turno)

### 2. **All Teams Simultaneous** (Todos los Equipos)
- Todos los equipos responden/juegan al mismo tiempo
- La ronda termina cuando todos los equipos han completado su acción
- Ejemplo: Trivia por equipos (todos responden cada pregunta)

### 3. **Sequential Within Team** (Secuencial dentro del Equipo)
- Un jugador de cada equipo juega en cada turno
- Los turnos rotan entre jugadores del mismo equipo
- Ejemplo: Juego de cartas donde cada jugador juega por turnos

## 🔧 Módulo: TeamsSystem

### Ubicación
`app/Services/Modules/TeamsSystem/TeamsManager.php`

### Responsabilidades

1. **Gestión de Equipos:**
   - Crear equipos automáticamente o manualmente
   - Asignar jugadores a equipos
   - Mover jugadores entre equipos
   - Balancear equipos automáticamente

2. **Puntuación por Equipos:**
   - Calcular puntuación agregada del equipo
   - Determinar equipo ganador
   - Generar ranking de equipos

3. **Validaciones:**
   - Verificar que todos los jugadores estén en un equipo
   - Validar número mínimo/máximo de miembros por equipo
   - Prevenir equipos vacíos al iniciar partida

### API del Módulo

```php
class TeamsManager
{
    protected GameMatch $match;
    protected string $cacheKey;
    protected int $syncInterval = 10; // Sincronizar a BD cada 10 segundos

    // Constructor
    public function __construct(GameMatch $match)
    {
        $this->match = $match;
        $this->cacheKey = "game:match:{$match->id}:state";

        // Cargar desde Redis o BD
        $this->loadOrInitialize();
    }

    protected function loadOrInitialize(): void
    {
        // Intentar cargar desde Redis
        if (!Cache::has($this->cacheKey)) {
            // No está en Redis, cargar desde BD
            $state = $this->match->game_state ?? $this->getInitialState();
            Cache::put($this->cacheKey, $state, now()->addHours(24));
        }
    }

    // Configuración
    public function isEnabled(): bool;
    public function getMode(): string;
    public function enableTeams(string $mode, int $numTeams = 2): void;
    public function disableTeams(): void;
    public function setAllowSelfSelection(bool $allow): void;
    public function getAllowSelfSelection(): bool;
    public function setMaxMembersPerTeam(?int $max): void;

    // Gestión de Equipos
    public function createTeam(string $name, ?string $color = null): array;  // Retorna el team
    public function deleteTeam(string $teamId): bool;
    public function assignPlayerToTeam(int $playerId, string $teamId): bool;
    public function removePlayerFromTeam(int $playerId): bool;
    public function balanceTeams(array $playerIds): void;  // Distribuir jugadores equitativamente

    // Consultas
    public function getTeams(): array;  // Array de equipos
    public function getTeam(string $teamId): ?array;
    public function getTeamMembers(string $teamId): array;  // Array de player_ids
    public function getPlayerTeam(int $playerId): ?array;
    public function getTeamScore(string $teamId): int;

    // Turnos
    public function getCurrentTeam(): ?array;
    public function getCurrentTeamId(): ?string;
    public function setCurrentTeam(string $teamId): void;
    public function nextTeam(): array;

    // Puntuación
    public function addTeamScore(string $teamId, int $points): void;
    public function getTeamRanking(): array;  // Array ordenado por puntuación
    public function getWinningTeam(): ?array;

    // Validaciones
    public function validateTeamsForStart(): array;  // Retorna errores si hay
    public function allTeamsReady(): bool;

    // Persistencia
    public function save(bool $syncToDB = false): void;
    public function syncToDatabase(): void;  // Forzar sync a BD
    protected function getState(): array;
    protected function setState(array $state): void;
}
```

### Implementación Interna (Con Redis)

El TeamsManager manipula el estado en Redis:

```php
class TeamsManager
{
    public function createTeam(string $name, ?string $color = null): array
    {
        return Cache::lock("game:match:{$this->match->id}:lock", 5)->block(3, function() use ($name, $color) {
            $state = $this->getState();
            $teamId = 'team_' . uniqid();
            $position = count($state['teams_config']['teams'] ?? []);

            $team = [
                'id' => $teamId,
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

            $state['teams_config']['teams'][] = $team;
            $this->setState($state);
            $this->save();

            return $team;
        });
    }

    public function assignPlayerToTeam(int $playerId, string $teamId): bool
    {
        return Cache::lock("game:match:{$this->match->id}:lock", 5)->block(3, function() use ($playerId, $teamId) {
            $state = $this->getState();

            // Remover de equipo anterior
            foreach ($state['teams_config']['teams'] as &$team) {
                $key = array_search($playerId, $team['members']);
                if ($key !== false) {
                    array_splice($team['members'], $key, 1);
                }
            }

            // Agregar al nuevo equipo
            foreach ($state['teams_config']['teams'] as &$team) {
                if ($team['id'] === $teamId) {
                    if (!in_array($playerId, $team['members'])) {
                        $team['members'][] = $playerId;
                    }
                    $this->setState($state);
                    $this->save();
                    return true;
                }
            }

            return false;
        });
    }

    protected function getState(): array
    {
        return Cache::get($this->cacheKey, []);
    }

    protected function setState(array $state): void
    {
        Cache::put($this->cacheKey, $state, now()->addHours(24));
    }

    protected function save(bool $syncToDB = false): void
    {
        // Sincronizar a BD cada X segundos o si se fuerza
        $lastSyncKey = "game:match:{$this->match->id}:last_sync";
        $lastSync = Cache::get($lastSyncKey, 0);

        if ($syncToDB || (time() - $lastSync) >= $this->syncInterval) {
            $this->syncToDatabase();
            Cache::put($lastSyncKey, time(), now()->addHours(24));
        }
    }

    public function syncToDatabase(): void
    {
        $state = $this->getState();
        $this->match->game_state = $state;
        $this->match->saveQuietly(); // Sin eventos para evitar overhead
    }
}
```

### Sincronización Automática

Agregar un Job que sincroniza periódicamente todas las partidas activas:

```php
// app/Jobs/SyncActiveMatchesToDatabase.php

class SyncActiveMatchesToDatabase implements ShouldQueue
{
    public function handle()
    {
        $activeMatches = GameMatch::whereNull('finished_at')
            ->where('started_at', '>', now()->subHours(24))
            ->get();

        foreach ($activeMatches as $match) {
            $cacheKey = "game:match:{$match->id}:state";

            if (Cache::has($cacheKey)) {
                $state = Cache::get($cacheKey);
                $match->game_state = $state;
                $match->saveQuietly();
            }
        }
    }
}

// Kernel.php - ejecutar cada 1 minuto
protected function schedule(Schedule $schedule)
{
    $schedule->job(new SyncActiveMatchesToDatabase)->everyMinute();
}
```

## 🔄 Integración con Managers Existentes

### RoundManager

**Cambios necesarios:**

```php
class RoundManager
{
    protected ?TeamsManager $teamsManager = null;

    public function setTeamsManager(?TeamsManager $teamsManager): void
    {
        $this->teamsManager = $teamsManager;
    }

    public function shouldEndSimultaneousRound(array $answers): bool
    {
        if (!$this->teamsManager || !$this->teamsManager->isEnabled()) {
            // Modo normal: todos los jugadores
            return count($answers) >= $this->getTotalPlayers();
        }

        // Modo equipos: verificar según el modo
        $mode = $this->teamsManager->getMode();

        if ($mode === 'team_turns') {
            // Solo el equipo actual debe responder
            $currentTeam = $this->teamsManager->getCurrentTeam();
            $teamMembers = $this->teamsManager->getTeamMembers($currentTeam->id);
            return $this->hasTeamCompleted($currentTeam->id, $answers);
        }

        if ($mode === 'all_teams') {
            // Todos los equipos deben responder
            return $this->allTeamsCompleted($answers);
        }

        return false;
    }

    protected function hasTeamCompleted(int $teamId, array $answers): bool
    {
        $teamMembers = $this->teamsManager->getTeamMembers($teamId);
        $teamAnswers = array_filter($answers, function($answer) use ($teamMembers) {
            return $teamMembers->contains('id', $answer['player_id']);
        });

        return count($teamAnswers) >= $teamMembers->count();
    }

    protected function allTeamsCompleted(array $answers): bool
    {
        $teams = $this->teamsManager->getTeams();

        foreach ($teams as $team) {
            if (!$this->hasTeamCompleted($team->id, $answers)) {
                return false;
            }
        }

        return true;
    }
}
```

### TurnManager

**⚠️ ACOPLAMIENTO CRÍTICO: TurnManager y TeamsManager**

El `TurnManager` está **fuertemente acoplado** con `TeamsManager` porque los equipos cambian fundamentalmente **cuándo se considera completado un turno**:

**Sin equipos**: Un turno = Una acción de un jugador
**Con equipos**: Un turno puede requerir que múltiples jugadores del equipo completen antes de avanzar

#### Nuevas Propiedades

```php
class TurnManager
{
    protected ?TeamsManager $teamsManager = null;

    // Tracking de completions del turno actual
    protected array $turnCompletions = [];

    // ¿Se requiere que TODOS los miembros completen?
    protected bool $requireAllTeamMembers = false;
}
```

#### Métodos Clave de Tracking

```php
// Marcar que un jugador completó su acción
$turnManager->markPlayerCompleted($playerId);

// Verificar si ya completó
$completed = $turnManager->hasPlayerCompleted($playerId);

// Verificar si el turno está completo (lógica según modo de equipo)
$status = $turnManager->isTurnComplete();
// Retorna: ['is_complete' => bool, 'reason' => string, 'completed_count' => int, 'total_count' => int]

// Verificar si se puede avanzar al siguiente turno
$canAdvance = $turnManager->canAdvanceTurn();
// Retorna: ['can_advance' => bool, 'reason' => string, 'details' => array]
```

#### Lógica de Completions según Modo

**Modo `team_turns` + requireAllTeamMembers=false:**
- Turno completo cuando **al menos 1 miembro** del equipo actual completó
- Ejemplo: Pictionary - solo el dibujante dibuja

**Modo `team_turns` + requireAllTeamMembers=true:**
- Turno completo cuando **TODOS los miembros** del equipo actual completaron
- Ejemplo: Trivia en equipo - todos deben responder

**Modo `all_teams`:**
- Turno completo cuando **todos los equipos tienen al menos 1 respuesta**
- Ejemplo: Pregunta simultánea - cada equipo envía una respuesta

#### Integración Completa

```php
class TurnManager
{
    protected ?TeamsManager $teamsManager = null;
    protected array $turnCompletions = [];
    protected bool $requireAllTeamMembers = false;

    public function setTeamsManager(?TeamsManager $teamsManager): void
    {
        $this->teamsManager = $teamsManager;
    }

    public function setRequireAllTeamMembers(bool $required): void
    {
        $this->requireAllTeamMembers = $required;
    }

    // Avanzar al siguiente turno (limpia completions)
    public function nextTurn(): array
    {
        if ($this->isPaused) {
            return $this->getCurrentTurnInfo();
        }

        // Limpiar completions del turno anterior
        $this->turnCompletions = [];

        $this->cycleJustCompleted = false;
        $playerCount = count($this->turnOrder);

        $this->currentTurnIndex += $this->direction;

        if ($this->direction === 1) {
            if ($this->currentTurnIndex >= $playerCount) {
                $this->currentTurnIndex = 0;
                $this->cycleJustCompleted = true;
            }
        } else {
            if ($this->currentTurnIndex < 0) {
                $this->currentTurnIndex = $playerCount - 1;
                $this->cycleJustCompleted = true;
            }
        }

        return $this->getCurrentTurnInfo();
    }

    // Verificar si el turno está completo (lógica específica por modo)
    public function isTurnComplete(): array
    {
        if (!$this->isTeamsMode()) {
            return [
                'is_complete' => true,
                'reason' => 'individual_turn',
                'completed_count' => 1,
                'total_count' => 1
            ];
        }

        $mode = $this->teamsManager->getMode();

        if ($mode === 'team_turns') {
            return $this->isTurnCompleteTeamTurns();
        }

        if ($mode === 'all_teams') {
            return $this->isTurnCompleteAllTeams();
        }

        return [
            'is_complete' => true,
            'reason' => 'sequential_individual',
            'completed_count' => count($this->turnCompletions),
            'total_count' => 1
        ];
    }

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

        if ($this->requireAllTeamMembers) {
            return [
                'is_complete' => $completedCount === $totalCount,
                'reason' => 'require_all_members',
                'completed_count' => $completedCount,
                'total_count' => $totalCount,
                'team_id' => $currentTeam['id']
            ];
        }

        return [
            'is_complete' => $completedCount > 0,
            'reason' => 'at_least_one_member',
            'completed_count' => $completedCount,
            'total_count' => $totalCount,
            'team_id' => $currentTeam['id']
        ];
    }

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

    // Avanzar turno considerando equipos
    public function nextTeamTurn(): array
    {
        if (!$this->isTeamsMode()) {
            return $this->nextTurn();
        }

        $mode = $this->teamsManager->getMode();

        if ($mode === 'team_turns') {
            $nextTeam = $this->teamsManager->nextTeam();

            return [
                'player_id' => null,
                'team_id' => $nextTeam['id'] ?? null,
                'team' => $nextTeam,
                'turn_index' => $this->currentTurnIndex,
                'cycle_completed' => $this->cycleJustCompleted,
                'mode' => 'team_turns'
            ];
        }

        return $this->nextTurn();
    }
}
```

#### Ejemplo de Uso en Juegos

**Pictionary (team_turns + requireAllTeamMembers=false):**
```php
// El dibujante completa su turno
$turnManager->setRequireAllTeamMembers(false);
$turnManager->markPlayerCompleted($drawerId);

// Verificar si puede avanzar (debería ser true, solo se requiere el dibujante)
$status = $turnManager->canAdvanceTurn();
if ($status['can_advance']) {
    $turnManager->nextTeamTurn();
}
```

**Trivia en Equipos (team_turns + requireAllTeamMembers=true):**
```php
// Todos los miembros del equipo responden
$turnManager->setRequireAllTeamMembers(true);

foreach ($teamMembers as $memberId) {
    $turnManager->markPlayerCompleted($memberId);
}

// Verificar si puede avanzar (true solo cuando todos completaron)
$status = $turnManager->canAdvanceTurn();
if ($status['can_advance']) {
    $turnManager->nextTeamTurn();
}
```

**Trivia Simultánea (all_teams):**
```php
// Cada equipo envía una respuesta (al menos un miembro)
$turnManager->markPlayerCompleted($teamLeaderId1);
$turnManager->markPlayerCompleted($teamLeaderId2);

// Verificar si puede avanzar (true cuando todos los equipos tienen respuesta)
$status = $turnManager->canAdvanceTurn();
if ($status['can_advance']) {
    $turnManager->nextTurn();
}
```

### ScoringSystem

**Cambios necesarios:**

```php
class ScoreCalculator
{
    public function calculateScore(
        Player $player,
        array $context,
        ?TeamsManager $teamsManager = null
    ): int {
        $individualScore = $this->calculateIndividualScore($player, $context);

        if ($teamsManager && $teamsManager->isEnabled()) {
            // Agregar puntos al equipo
            $team = $teamsManager->getPlayerTeam($player->id);
            if ($team) {
                $teamsManager->addTeamScore($team->id, $individualScore);
            }
        }

        return $individualScore;
    }
}
```

## 🎨 UI - Gestión de Equipos en Lobby

### Flujo Completo

#### 1. **Master: Configuración Inicial**

**Paso 1: Activar Modo Equipos**
- Toggle "Jugar por Equipos" (solo master)
- Al activar, se muestra el panel de configuración

**Paso 2: Configurar Equipos**
- Número de equipos (2-4)
- Para cada equipo:
  - Nombre (editable: "Equipo Rojo", "Equipo Azul", etc.)
  - Color (selector de color)
- Modo de juego: Team Turns / All Teams / Sequential
- Botón "Guardar Configuración"

**Paso 3: Asignación de Jugadores**

El master tiene 3 opciones:

**Opción A: Asignación Manual**
- Drag & Drop de jugadores entre equipos
- Click en jugador → Menú "Mover a equipo..."

**Opción B: Asignación Aleatoria Balanceada**
- Botón "Distribuir Aleatoriamente"
- Distribuye jugadores equitativamente
- Mantiene balance (diferencia máxima de 1 jugador)

**Opción C: Autoselección de Jugadores**
- Toggle "Permitir que jugadores elijan equipo"
- Los jugadores ven los equipos y pueden unirse
- Master puede mover jugadores si es necesario

#### 2. **Jugadores: Selección de Equipo**

**Cuando "Autoselección" está ACTIVA:**

Los jugadores ven:
```
┌─────────────────────────────────────┐
│  Elige tu Equipo                    │
├─────────────────────────────────────┤
│  🔴 Equipo Rojo         [3/4]       │
│     • Juan                          │
│     • María                         │
│     • Pedro                         │
│     [UNIRSE] [Lleno/Botón]         │
├─────────────────────────────────────┤
│  🔵 Equipo Azul         [2/4]       │
│     • Ana                           │
│     • Luis                          │
│     [UNIRSE]                        │
├─────────────────────────────────────┤
│  🟢 Equipo Verde        [1/4]       │
│     • Carlos                        │
│     [UNIRSE]                        │
└─────────────────────────────────────┘
```

**Restricciones:**
- Solo pueden unirse si hay espacio (max_members_per_team)
- Pueden cambiar de equipo antes de que inicie la partida
- Master puede forzar movimientos

**Cuando "Autoselección" está DESACTIVADA:**

Los jugadores ven:
```
┌─────────────────────────────────────┐
│  Esperando asignación de equipos... │
│                                     │
│  El organizador está formando       │
│  los equipos.                       │
└─────────────────────────────────────┘
```

#### 3. **Panel de Equipos en Lobby**

**Vista del Master:**
```
┌──────────────────────────────────────────────────────────┐
│ ⚙️ Configuración de Equipos                              │
├──────────────────────────────────────────────────────────┤
│ ☑️ Modo Equipos ACTIVADO                                 │
│                                                           │
│ Modo de Juego: [All Teams ▼]                            │
│ Número de Equipos: [3]                                   │
│                                                           │
│ [⚡ Distribuir Aleatoriamente] [👥 Permitir Autoselección]│
├──────────────────────────────────────────────────────────┤
│ 🔴 Equipo Rojo (3 jugadores)          [✏️ Editar] [❌]  │
│    • Juan ⭐ (tú)                      [Mover ▼]         │
│    • María                             [Mover ▼]         │
│    • Pedro                             [Mover ▼]         │
├──────────────────────────────────────────────────────────┤
│ 🔵 Equipo Azul (2 jugadores)          [✏️ Editar] [❌]  │
│    • Ana                               [Mover ▼]         │
│    • Luis                              [Mover ▼]         │
├──────────────────────────────────────────────────────────┤
│ 🟢 Equipo Verde (1 jugador)           [✏️ Editar] [❌]  │
│    • Carlos                            [Mover ▼]         │
├──────────────────────────────────────────────────────────┤
│ ⚠️ Equipos desbalanceados (diferencia de 2 jugadores)    │
│                                                           │
│ [➕ Agregar Equipo]              [🎮 Iniciar Partida]    │
└──────────────────────────────────────────────────────────┘
```

**Vista del Jugador (Autoselección Activa):**
```
┌──────────────────────────────────────────────────────────┐
│ 👥 Equipos - Elige tu equipo                             │
├──────────────────────────────────────────────────────────┤
│ 🔴 Equipo Rojo (3/6 jugadores)                          │
│    • Juan ⭐                                              │
│    • María                                               │
│    • Pedro                                               │
│    [CAMBIAR A ESTE EQUIPO]                               │
├──────────────────────────────────────────────────────────┤
│ 🔵 Equipo Azul (2/6 jugadores)       ✓ Tu equipo        │
│    • Ana                                                 │
│    • Luis (tú) ⭐                                         │
│    [SALIR DEL EQUIPO]                                    │
├──────────────────────────────────────────────────────────┤
│ 🟢 Equipo Verde (1/6 jugadores)                         │
│    • Carlos                                              │
│    [CAMBIAR A ESTE EQUIPO]                               │
└──────────────────────────────────────────────────────────┘
```

**Vista del Jugador (Asignación por Master):**
```
┌──────────────────────────────────────────────────────────┐
│ 👥 Equipos                                                │
├──────────────────────────────────────────────────────────┤
│ 🔴 Equipo Rojo (3 jugadores)                            │
│    • Juan ⭐                                              │
│    • María                                               │
│    • Pedro                                               │
├──────────────────────────────────────────────────────────┤
│ 🔵 Equipo Azul (2 jugadores)         ✓ Tu equipo        │
│    • Ana                                                 │
│    • Luis (tú) ⭐                                         │
├──────────────────────────────────────────────────────────┤
│ 🟢 Equipo Verde (1 jugador)                             │
│    • Carlos                                              │
├──────────────────────────────────────────────────────────┤
│ ℹ️ El organizador está formando los equipos              │
└──────────────────────────────────────────────────────────┘
```

### 4. **Validaciones Antes de Iniciar**

El botón "Iniciar Partida" se deshabilita si:

❌ Hay jugadores sin equipo
❌ Hay equipos vacíos
❌ Equipos muy desbalanceados (diferencia > 2 jugadores)
❌ Menos de 2 equipos
❌ No hay suficientes jugadores (min del juego)

Se muestra lista de errores:
```
⚠️ No puedes iniciar la partida:
  • 2 jugadores sin equipo asignado
  • Equipo Verde está vacío
  • Diferencia de 3 jugadores entre equipos
```

### Eventos WebSocket

Nuevos eventos para sincronización en tiempo real:

```json
{
  "TeamCreatedEvent": {
    "name": ".lobby.team.created",
    "handler": "handleTeamCreated"
  },
  "TeamDeletedEvent": {
    "name": ".lobby.team.deleted",
    "handler": "handleTeamDeleted"
  },
  "PlayerMovedToTeamEvent": {
    "name": ".lobby.player.moved",
    "handler": "handlePlayerMoved"
  },
  "TeamsConfigUpdatedEvent": {
    "name": ".lobby.teams.config.updated",
    "handler": "handleTeamsConfigUpdated"
  }
}
```

## 📝 Configuración en capabilities.json

Los juegos que soporten equipos deben declararlo:

```json
{
  "slug": "trivia",
  "requires": {
    "modules": {
      "teams_system": "^1.0"  // Declarar dependencia
    }
  },
  "teams_config": {
    "supported": true,
    "modes": ["all_teams"],  // Modos soportados
    "min_teams": 2,
    "max_teams": 4,
    "min_members_per_team": 1,
    "max_members_per_team": null  // null = sin límite
  }
}
```

## 🧪 Tests

### Tests Unitarios

```php
// tests/Unit/Modules/TeamsManagerTest.php
class TeamsManagerTest extends TestCase
{
    public function test_create_team()
    public function test_assign_player_to_team()
    public function test_remove_player_from_team()
    public function test_balance_teams()
    public function test_team_scoring()
    public function test_team_ranking()
    public function test_validate_teams_for_start()
}
```

### Tests de Integración

```php
// tests/Feature/Teams/TeamGameFlowTest.php
class TeamGameFlowTest extends TestCase
{
    public function test_trivia_game_with_teams()
    public function test_pictionary_game_with_teams()
    public function test_team_turns_mode()
    public function test_all_teams_simultaneous_mode()
}
```

## 🚀 Plan de Implementación

### Fase 1: Fundamentos (1-2 días)
1. ✅ Crear migraciones (teams, team_members)
2. ✅ Crear modelos Eloquent (Team, TeamMember)
3. ✅ Implementar TeamsManager básico
4. ✅ Tests unitarios del TeamsManager

### Fase 2: Integración con Managers (1 día)
5. ✅ Adaptar RoundManager para equipos
6. ✅ Adaptar TurnManager para equipos
7. ✅ Adaptar ScoringSystem para equipos
8. ✅ Tests de integración de managers

### Fase 3: API y Backend (1 día)
9. ✅ Rutas API para gestión de equipos
10. ✅ Controlador TeamController
11. ✅ Validaciones y reglas de negocio
12. ✅ Eventos WebSocket para equipos

### Fase 4: UI del Lobby (1-2 días)
13. ✅ Toggle y configuración de equipos
14. ✅ Panel de equipos con drag & drop
15. ✅ Sincronización en tiempo real
16. ✅ Validaciones visuales

### Fase 5: Adaptación de Juegos (1 día)
17. ✅ Actualizar Trivia para soportar equipos
18. ✅ Actualizar Pictionary para soportar equipos
19. ✅ Actualizar vistas de juego
20. ✅ Tests de juegos con equipos

### Fase 6: Refinamiento (1 día)
21. ✅ Documentación completa
22. ✅ Tests end-to-end
23. ✅ Pulido de UI/UX
24. ✅ Convención de equipos en GAMES_CONVENTION.md

**Total estimado: 6-8 días**

## 🔍 Consideraciones Técnicas

### Retrocompatibilidad
- Juegos sin `teams_system` siguen funcionando normalmente
- `teams_config.enabled = false` por defecto
- Si no hay equipos, todo funciona como siempre

### Concurrencia
- Múltiples salas con equipos independientes ✅ (ya soportado)
- Cada match tiene sus propios equipos
- WebSocket channels por sala ✅ (ya implementado)

### Escalabilidad
- TeamsManager es stateless (lee de BD)
- Caching de equipos en game_state para performance
- Invalidación de cache al modificar equipos

### Seguridad
- Solo el master puede crear/eliminar equipos
- Solo el master puede mover jugadores
- Validaciones server-side en todas las operaciones

---

**Última actualización:** 2025-10-22
