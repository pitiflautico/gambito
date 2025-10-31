# Sistema de Equipos - Diseño e Implementación

## 📊 Análisis del Estado Actual

### ✅ Lo que YA tenemos:
- **TeamsManager** con gestión de equipos en lobby
- Configuración de equipos (enabled/disabled, mode, teams array)
- Eventos de lobby (TeamCreatedEvent, PlayerMovedToTeamEvent, etc.)
- UI de lobby para crear/organizar equipos
- Método `getCurrentTeam()` y `getCurrentTeamId()`

### ❌ Lo que FALTA (y vamos a implementar):
1. **Integración con el sistema de juego**
2. **Modos de juego por equipos** (sequential vs simultaneous)
3. **Rotación de equipo activo** (en modo sequential)
4. **Roles dentro de equipos**
5. **Puntuación por equipos**
6. **Rol "viewer" para equipos inactivos**
7. **Filtrado de jugadores** por equipo activo

---

## 🎮 Casos de Uso

### Caso 1: Mockup por Equipos (Sequential)
```
Equipos: Rojo (3 jugadores), Azul (3 jugadores)
Mode: "sequential" - Un equipo por ronda

Ronda 1:
  Equipo Rojo: ACTIVO
    - Jugador 1: asker
    - Jugador 2: guesser
    - Jugador 3: guesser

  Equipo Azul: INACTIVO (viewer)
    - Jugador 4: viewer
    - Jugador 5: viewer
    - Jugador 6: viewer

Ronda 2:
  Equipo Azul: ACTIVO
    - Jugador 4: asker (rotó dentro del equipo)
    - Jugador 5: guesser
    - Jugador 6: guesser

  Equipo Rojo: INACTIVO (viewer)
    - Jugador 2: viewer (antes era asker, ahora espectador)
    - Jugador 1: viewer
    - Jugador 3: viewer

Ronda 3: Vuelve al Equipo Rojo, pero ahora Jugador 2 es asker...
```

### Caso 2: Trivia por Equipos (Simultaneous)
```
Equipos: Rojo, Azul, Verde
Mode: "simultaneous" - Todos los equipos juegan a la vez

Ronda 1:
  Equipo Rojo:
    - Todos: player (o 1 capitán que responde)

  Equipo Azul:
    - Todos: player

  Equipo Verde:
    - Todos: player

Todos responden, todos suman puntos a su equipo
```

---

## 🏗️ Arquitectura Propuesta

### 1. Configuración en config.json

```json
{
  "modules": {
    "teams_system": {
      "enabled": true,
      "mode": "sequential",  // "sequential" | "simultaneous"
      "score_by_team": true,  // Puntuación por equipo vs individual
      "rotate_teams": true,    // Rotar equipo activo cada ronda
      "inactive_role": "viewer"  // Rol para jugadores de equipos inactivos
    },
    "roles_system": {
      "enabled": true,
      "roles": [
        {
          "name": "asker",
          "count": 1,
          "description": "El jugador que hace las preguntas",
          "rotate_on_round_start": true,
          "scope": "team"  // NEW: "team" | "global"
        },
        {
          "name": "guesser",
          "count": -1,
          "description": "Los jugadores que adivinan",
          "rotate_on_round_start": false,
          "scope": "team"
        },
        {
          "name": "viewer",
          "count": 0,
          "description": "Espectador (equipo inactivo)",
          "rotate_on_round_start": false,
          "scope": "global"  // Se asigna a equipos completos
        }
      ]
    }
  }
}
```

**Nuevos campos**:
- `teams_system.mode`: "sequential" (un equipo por ronda) o "simultaneous" (todos a la vez)
- `teams_system.rotate_teams`: Si rotar equipo activo cada ronda
- `teams_system.inactive_role`: Rol para jugadores inactivos
- `roles_system.roles[].scope`: "team" (dentro del equipo) o "global" (todos los jugadores)

---

### 2. Modificaciones a TeamsManager

**Métodos nuevos**:

```php
class TeamsManager
{
    /**
     * Obtener equipo activo en modo sequential
     */
    public function getActiveTeam(): ?array
    {
        if ($this->getMode() !== 'sequential') {
            return null; // En simultaneous, todos están activos
        }

        $state = $this->getState();
        $currentIndex = $state['teams_config']['current_team_index'] ?? 0;
        $teams = $state['teams_config']['teams'] ?? [];

        return $teams[$currentIndex] ?? null;
    }

    /**
     * Rotar al siguiente equipo (modo sequential)
     */
    public function rotateActiveTeam(): ?int
    {
        if ($this->getMode() !== 'sequential') {
            return null;
        }

        $state = $this->getState();
        $teams = $state['teams_config']['teams'] ?? [];
        $currentIndex = $state['teams_config']['current_team_index'] ?? 0;

        // Circular rotation
        $nextIndex = ($currentIndex + 1) % count($teams);

        $state['teams_config']['current_team_index'] = $nextIndex;
        $this->setState($state);
        $this->save();

        return $nextIndex;
    }

    /**
     * Obtener IDs de jugadores del equipo activo
     */
    public function getActiveTeamPlayerIds(): array
    {
        $activeTeam = $this->getActiveTeam();
        return $activeTeam ? $activeTeam['player_ids'] : [];
    }

    /**
     * Obtener IDs de jugadores de equipos inactivos
     */
    public function getInactivePlayersIds(): array
    {
        if ($this->getMode() !== 'sequential') {
            return [];
        }

        $activeTeamIds = $this->getActiveTeamPlayerIds();
        $allPlayerIds = $this->getAllPlayerIds();

        return array_diff($allPlayerIds, $activeTeamIds);
    }

    /**
     * Verificar si un jugador pertenece al equipo activo
     */
    public function isPlayerInActiveTeam(int $playerId): bool
    {
        return in_array($playerId, $this->getActiveTeamPlayerIds());
    }

    /**
     * Obtener ID del equipo de un jugador
     */
    public function getTeamIdForPlayer(int $playerId): ?string
    {
        $state = $this->getState();
        $teams = $state['teams_config']['teams'] ?? [];

        foreach ($teams as $team) {
            if (in_array($playerId, $team['player_ids'])) {
                return $team['id'];
            }
        }

        return null;
    }

    /**
     * Puntuación: Añadir puntos al equipo
     */
    public function addPointsToTeam(string $teamId, int $points): void
    {
        $state = $this->getState();
        $teams = $state['teams_config']['teams'] ?? [];

        foreach ($teams as &$team) {
            if ($team['id'] === $teamId) {
                $team['score'] = ($team['score'] ?? 0) + $points;
                break;
            }
        }

        $state['teams_config']['teams'] = $teams;
        $this->setState($state);
        $this->save();
    }

    /**
     * Obtener puntuaciones de todos los equipos
     */
    public function getTeamScores(): array
    {
        $state = $this->getState();
        $teams = $state['teams_config']['teams'] ?? [];

        $scores = [];
        foreach ($teams as $team) {
            $scores[$team['id']] = [
                'name' => $team['name'],
                'score' => $team['score'] ?? 0,
                'color' => $team['color']
            ];
        }

        return $scores;
    }
}
```

---

### 3. Modificaciones a PlayerManager

**Integración con equipos** (PlayerManager gestiona TODO):

```php
class PlayerManager
{
    protected ?TeamsManager $teamsManager = null;

    /**
     * Constructor - ahora acepta TeamsManager opcional
     */
    public function __construct(
        array $playerIds,
        ?ScoreCalculatorInterface $scoreCalculator = null,
        array $config = [],
        ?TeamsManager $teamsManager = null
    ) {
        $this->players = [];
        $this->scoreCalculator = $scoreCalculator;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->teamsManager = $teamsManager;

        foreach ($playerIds as $playerId) {
            $this->players[$playerId] = new PlayerData($playerId);
        }
    }

    /**
     * Método estático para crear desde game_state
     */
    public static function fromArray(
        array $gameState,
        ?ScoreCalculatorInterface $scoreCalculator = null,
        ?TeamsManager $teamsManager = null
    ): self {
        // ... código existente ...

        $instance = new self($playerIds, $scoreCalculator, $config, $teamsManager);

        // ... resto del código ...
    }

    /**
     * Asignar roles según modo de equipos (auto-detecta)
     */
    public function autoAssignRolesFromConfig(
        array $rolesConfig,
        bool $shuffle = false
    ): array {
        // Auto-detectar si hay equipos en modo sequential
        if ($this->teamsManager &&
            $this->teamsManager->isEnabled() &&
            $this->teamsManager->getMode() === 'sequential') {
            return $this->assignRolesToActiveTeam($rolesConfig);
        }

        // Modo simultaneous o sin equipos: asignar a todos
        return $this->assignRolesToAllPlayers($rolesConfig, $shuffle);
    }

    /**
     * Reasignar roles (usado cuando cambia el equipo activo)
     */
    public function reassignRoles(array $rolesConfig): array
    {
        // Limpiar todos los roles actuales
        foreach ($this->players as $player) {
            $player->persistentRoles = [];
        }

        // Reasignar según configuración actual
        return $this->autoAssignRolesFromConfig($rolesConfig);
    }

    /**
     * Asignar roles solo al equipo activo (modo sequential)
     * PlayerManager gestiona TODO internamente
     */
    protected function assignRolesToActiveTeam(array $rolesConfig): array
    {
        if (!$this->teamsManager) {
            throw new \RuntimeException('TeamsManager not set');
        }

        $activePlayerIds = $this->teamsManager->getActiveTeamPlayerIds();
        $inactivePlayerIds = $this->teamsManager->getInactivePlayersIds();
        $inactiveRole = $this->getInactiveRole($rolesConfig);

        $assignments = [];

        // Asignar rol de viewer a jugadores inactivos
        foreach ($inactivePlayerIds as $playerId) {
            $this->assignPersistentRole($playerId, $inactiveRole);
            $assignments[$playerId] = [$inactiveRole];
        }

        // Asignar roles del equipo activo
        foreach ($rolesConfig as $roleConfig) {
            if (($roleConfig['scope'] ?? 'team') === 'team') {
                $assignments = array_merge(
                    $assignments,
                    $this->assignTeamRole($roleConfig, $activePlayerIds)
                );
            }
        }

        return $assignments;
    }

    /**
     * Rotar rol dentro del equipo activo
     * PlayerManager gestiona TODO internamente
     */
    public function rotateRoleWithinTeam(string $roleName, array $rolesConfig): ?int
    {
        if (!$this->teamsManager) {
            // Sin equipos, usar rotación normal
            return $this->rotateRole($roleName, $rolesConfig);
        }

        // Solo rotar dentro del equipo activo
        $activePlayerIds = $this->teamsManager->getActiveTeamPlayerIds();

        // Filtrar jugadores del rol solo del equipo activo
        $currentPlayers = $this->getPlayersWithPersistentRole($roleName);
        $currentPlayers = array_intersect($currentPlayers, $activePlayerIds);

        if (empty($currentPlayers)) {
            // Primera vez, asignar al primer jugador del equipo
            $nextPlayerId = $activePlayerIds[0];
        } else {
            $currentPlayerId = $currentPlayers[0];
            $currentIndex = array_search($currentPlayerId, $activePlayerIds);
            $nextIndex = ($currentIndex + 1) % count($activePlayerIds);
            $nextPlayerId = $activePlayerIds[$nextIndex];

            // Remover rol del jugador actual
            $this->removePersistentRole($currentPlayerId, $roleName);

            // Asignar rol alternativo si existe
            $alternativeRole = $this->getAlternativeRole($rolesConfig, $roleName);
            if ($alternativeRole) {
                $this->assignPersistentRole($currentPlayerId, $alternativeRole);
            }
        }

        $this->assignPersistentRole($nextPlayerId, $roleName);
        return $nextPlayerId;
    }

    /**
     * Obtener rol para jugadores inactivos
     */
    protected function getInactiveRole(array $rolesConfig): string
    {
        foreach ($rolesConfig as $roleConfig) {
            if ($roleConfig['scope'] === 'global' && $roleConfig['name'] === 'viewer') {
                return 'viewer';
            }
        }

        return 'viewer'; // Fallback
    }

    /**
     * Obtener rol alternativo (para cuando rota el principal)
     */
    protected function getAlternativeRole(array $rolesConfig, string $mainRole): ?string
    {
        foreach ($rolesConfig as $roleConfig) {
            if ($roleConfig['name'] !== $mainRole &&
                $roleConfig['scope'] === 'team' &&
                ($roleConfig['count'] ?? 0) === -1) {
                return $roleConfig['name'];
            }
        }

        return null;
    }
}
```

---

### 4. Modificaciones a BaseGameEngine

**Integración simplificada** (PlayerManager gestiona roles, BaseGameEngine solo rota equipos):

```php
class BaseGameEngine
{
    /**
     * Auto-rotar equipos al iniciar ronda
     */
    protected function handleNewRound(GameMatch $match, bool $advanceRound = true): void
    {
        // ... código existente ...

        // 2.3. LÓGICA BASE: Rotar equipo activo (si está en modo sequential)
        $teamsEnabled = $this->isModuleEnabled($match, 'teams_system');
        if ($teamsEnabled && $advanceRound) {
            $teamsManager = $this->getTeamsManager($match);

            if ($teamsManager->getMode() === 'sequential' && $teamsManager->isEnabled()) {
                // Rotar al siguiente equipo
                $nextTeamIndex = $teamsManager->rotateActiveTeam();

                Log::info("[{$this->getGameSlug()}] Team rotated", [
                    'match_id' => $match->id,
                    'next_team_index' => $nextTeamIndex,
                    'active_team' => $teamsManager->getActiveTeam()['name'] ?? null
                ]);

                // PlayerManager reasigna roles automáticamente
                $this->reassignRolesToActiveTeam($match);
            }
        }

        // 2.4. LÓGICA BASE: Rotar roles automáticamente
        // ... código existente de rotación de roles ...
    }

    /**
     * Reasignar roles cuando cambia el equipo activo
     * SIMPLIFICADO: PlayerManager gestiona todo
     */
    protected function reassignRolesToActiveTeam(GameMatch $match): void
    {
        $rolesConfig = $match->game_state['_config']['modules']['roles_system']['roles'] ?? [];
        $playerManager = $this->getPlayerManager($match);

        // PlayerManager gestiona TODA la lógica (equipos incluidos)
        $playerManager->reassignRoles($rolesConfig);
        $this->savePlayerManager($match, $playerManager);
    }

    /**
     * Obtener TeamsManager (cached)
     */
    protected function getTeamsManager(GameMatch $match): TeamsManager
    {
        if (!isset($this->teamsManager)) {
            $this->teamsManager = new TeamsManager($match);
        }
        return $this->teamsManager;
    }

    /**
     * Obtener PlayerManager con TeamsManager integrado
     * MODIFICADO: Ahora pasa TeamsManager al constructor
     */
    protected function getPlayerManager(
        GameMatch $match,
        ?ScoreCalculatorInterface $scoreCalculator = null
    ): PlayerManager {
        // Si ya existe en game_state, restaurar
        if (isset($match->game_state['player_system'])) {
            $teamsManager = $this->isModuleEnabled($match, 'teams_system')
                ? $this->getTeamsManager($match)
                : null;

            return PlayerManager::fromArray(
                $match->game_state,
                $scoreCalculator ?? $this->scoreCalculator,
                $teamsManager  // ← NUEVO: pasar TeamsManager
            );
        }

        // Si no existe, crear nuevo
        $playerIds = $match->players->pluck('id')->toArray();
        $teamsManager = $this->isModuleEnabled($match, 'teams_system')
            ? $this->getTeamsManager($match)
            : null;

        $playerManager = new PlayerManager(
            $playerIds,
            $scoreCalculator ?? $this->scoreCalculator,
            [
                'available_roles' => $match->game_state['_config']['modules']['roles_system']['roles'] ?? [],
                'allow_multiple_persistent_roles' => false,
                'track_score_history' => false,
            ],
            $teamsManager  // ← NUEVO: pasar TeamsManager
        );

        // Guardar estado inicial
        $this->savePlayerManager($match, $playerManager);

        Log::info("[{$this->getGameSlug()}] PlayerManager initialized", [
            'match_id' => $match->id,
            'player_count' => count($playerIds),
            'with_teams' => $teamsManager !== null
        ]);

        return $playerManager;
    }

    /**
     * Filtrar jugadores para acciones (solo equipo activo en sequential)
     */
    protected function canPlayerPerformAction(GameMatch $match, Player $player): bool
    {
        $teamsManager = $this->getTeamsManager($match);

        if ($teamsManager->isEnabled() && $teamsManager->getMode() === 'sequential') {
            // Solo jugadores del equipo activo pueden actuar
            if (!$teamsManager->isPlayerInActiveTeam($player->id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Añadir puntos (por equipo o individual)
     */
    protected function awardPoints(
        GameMatch $match,
        int $playerId,
        int $points,
        string $reason = ''
    ): void {
        $teamsManager = $this->getTeamsManager($match);
        $teamConfig = $match->game_state['_config']['modules']['teams_system'] ?? [];

        if ($teamsManager->isEnabled() && ($teamConfig['score_by_team'] ?? false)) {
            // Puntuación por equipo
            $teamId = $teamsManager->getTeamIdForPlayer($playerId);
            if ($teamId) {
                $teamsManager->addPointsToTeam($teamId, $points);

                Log::info("[{$this->getGameSlug()}] Points awarded to team", [
                    'team_id' => $teamId,
                    'points' => $points,
                    'reason' => $reason
                ]);
            }
        } else {
            // Puntuación individual
            $playerManager = $this->getPlayerManager($match);
            $playerManager->awardPoints($match, $playerId, $points);
        }
    }
}
```

---

## 📋 Plan de Implementación por Fases

### FASE 1: Configuración Base (1-2 horas)
**Objetivo**: Añadir campos de configuración

- [ ] Añadir `mode`, `rotate_teams`, `inactive_role`, `score_by_team` a config.json
- [ ] Añadir `scope` a roles_system.roles
- [ ] Actualizar TeamsManager con campo `current_team_index`
- [ ] Testing: Validar que configuración se carga correctamente

---

### FASE 2: TeamsManager - Métodos de Equipo Activo (2-3 horas)
**Objetivo**: Implementar lógica de equipo activo

- [ ] Implementar `getActiveTeam()`
- [ ] Implementar `rotateActiveTeam()`
- [ ] Implementar `getActiveTeamPlayerIds()`
- [ ] Implementar `getInactivePlayersIds()`
- [ ] Implementar `isPlayerInActiveTeam()`
- [ ] Testing: Rotar equipos y verificar jugadores correctos

---

### FASE 3: PlayerManager - Roles por Equipo (3-4 horas)
**Objetivo**: Asignar roles solo al equipo activo

- [ ] Modificar `autoAssignRolesFromConfig()` para recibir TeamsManager
- [ ] Implementar `assignRolesToActiveTeam()`
- [ ] Implementar `assignRolesToAllPlayers()` (renombrar existente)
- [ ] Implementar `rotateRoleWithinTeam()`
- [ ] Implementar helpers: `getInactiveRole()`, `getAlternativeRole()`
- [ ] Testing: Asignar roles a equipo activo, verificar viewers en inactivos

---

### FASE 4: BaseGameEngine - Rotación de Equipos (2-3 horas)
**Objetivo**: Rotar equipos automáticamente

- [ ] Añadir sección 2.3 en `handleNewRound()` para rotar equipos
- [ ] Implementar `reassignRolesToActiveTeam()`
- [ ] Implementar `getTeamsManager()`
- [ ] Modificar rotación de roles para usar `rotateRoleWithinTeam()` si hay equipos
- [ ] Testing: Avanzar ronda, verificar que equipo rota

---

### FASE 5: BaseGameEngine - Filtros y Puntuación (2-3 horas)
**Objetivo**: Filtrar acciones y manejar puntos

- [ ] Implementar `canPlayerPerformAction()`
- [ ] Usar filtro en `processRoundAction()` de BaseGameEngine
- [ ] Modificar `awardPoints()` para soportar puntuación por equipo
- [ ] Implementar métodos de puntuación en TeamsManager
- [ ] Testing: Solo equipo activo puede actuar, puntos van al equipo

---

### FASE 6: MockupEngine - Integración (2-3 horas)
**Objetivo**: Probar en Mockup game

- [ ] Actualizar `config.json` de Mockup con configuración de equipos
- [ ] Modificar `initialize()` para pasar TeamsManager a PlayerManager
- [ ] Modificar `processRoundAction()` para usar `canPlayerPerformAction()`
- [ ] Actualizar contador de guessers para filtrar por equipo activo
- [ ] Testing manual: Crear partida con equipos, verificar todo funciona

---

### FASE 7: Frontend - UI de Equipos (3-4 horas)
**Objetivo**: Mostrar equipo activo y roles

- [ ] Añadir indicador de "Equipo Activo" en UI
- [ ] Mostrar lista de equipos con estado (activo/inactivo)
- [ ] Colorear jugadores según su equipo
- [ ] Mostrar puntuación por equipos
- [ ] Actualizar display cuando rota equipo
- [ ] Testing: Verificar UI se actualiza correctamente

---

### FASE 8: Documentación y Testing Final (1-2 horas)
**Objetivo**: Documentar y validar

- [ ] Crear `docs/TEAMS_SYSTEM_IMPLEMENTATION.md`
- [ ] Actualizar comando `/create-game` con info de equipos
- [ ] Testing completo con 2-4 equipos
- [ ] Testing modo sequential vs simultaneous
- [ ] Verificar rotación de roles dentro de equipos

---

## 🎯 Ejemplo Completo: Mockup con Equipos

### Config.json
```json
{
  "modules": {
    "teams_system": {
      "enabled": true,
      "mode": "sequential",
      "score_by_team": true,
      "rotate_teams": true,
      "inactive_role": "viewer"
    },
    "roles_system": {
      "enabled": true,
      "roles": [
        {
          "name": "asker",
          "count": 1,
          "scope": "team",
          "rotate_on_round_start": true
        },
        {
          "name": "guesser",
          "count": -1,
          "scope": "team",
          "rotate_on_round_start": false
        },
        {
          "name": "viewer",
          "count": 0,
          "scope": "global",
          "rotate_on_round_start": false
        }
      ]
    }
  }
}
```

### Flujo del Juego
```
Lobby:
  Configurar 2 equipos: Rojo (3 players), Azul (3 players)
  Iniciar partida

Ronda 1:
  Equipo Activo: Rojo (index 0)
  Roles:
    - Player 1 (Rojo): asker
    - Player 2 (Rojo): guesser
    - Player 3 (Rojo): guesser
    - Player 4 (Azul): viewer
    - Player 5 (Azul): viewer
    - Player 6 (Azul): viewer

  Solo Player 1,2,3 pueden hacer acciones
  Puntos van al Equipo Rojo

Ronda 2:
  rotateActiveTeam() → Equipo Azul (index 1)
  reassignRolesToActiveTeam() → Limpiar y reasignar

  Equipo Activo: Azul
  Roles:
    - Player 4 (Azul): asker (rotó dentro del equipo)
    - Player 5 (Azul): guesser
    - Player 6 (Azul): guesser
    - Player 1 (Rojo): viewer
    - Player 2 (Rojo): viewer
    - Player 3 (Rojo): viewer

  Solo Player 4,5,6 pueden hacer acciones
  Puntos van al Equipo Azul

Ronda 3:
  Vuelve al Equipo Rojo
  Pero ahora Player 2 es asker (rotó dentro del equipo)
```

---

## ✅ Checklist de Validación

- [ ] Equipos se crean correctamente en lobby
- [ ] Al iniciar partida, roles se asignan solo al equipo activo
- [ ] Jugadores de equipos inactivos tienen rol "viewer"
- [ ] Al avanzar ronda, equipo activo rota (sequential mode)
- [ ] Roles rotan solo dentro del equipo activo
- [ ] Solo jugadores del equipo activo pueden hacer acciones
- [ ] Puntos se suman al equipo (si score_by_team: true)
- [ ] Frontend muestra equipo activo claramente
- [ ] Funcionamiento en modo simultaneous (todos los equipos activos)
- [ ] Documentación completa

---

## 🚀 Próximos Pasos

1. Revisar y aprobar este diseño
2. Empezar con FASE 1 (Configuración Base)
3. Ir fase por fase, testeando cada una
4. Probar en Mockup game
5. Documentar resultados
