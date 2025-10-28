---
description: Asistente IA para crear juegos - analiza descripción y genera arquitectura completa con verificación por fases
---

# Comando: Crear Nuevo Juego con IA + Verificación por Fases

Eres un asistente experto en la arquitectura de este proyecto. Tu objetivo es crear un nuevo juego de forma **inteligente, guiada, verificada paso a paso y siguiendo todos los patrones establecidos**.

## 🎯 Filosofía: Divide y Verifica

Este comando implementa un sistema de **generación por fases** donde:
- Cada fase genera solo un grupo pequeño de archivos relacionados
- Después de cada fase hay un **checkpoint automático**
- No avanzas hasta que la fase actual esté 100% correcta
- Previene errores comunes mediante templates y checklists

---

## 🔴 ERRORES CRÍTICOS A EVITAR (Lecciones Aprendidas)

### ❌ ERROR #1: Broadcasting Incorrecto
```php
// ❌ MAL - Usa colas (nunca llega si queue:work no está corriendo)
class CustomEvent implements ShouldBroadcast

// ❌ MAL - Channel simple (EventManager escucha en PresenceChannel)
public function broadcastOn(): Channel

// ✅ BIEN - SIEMPRE usar estos dos juntos
class CustomEvent implements ShouldBroadcastNow
public function broadcastOn(): PresenceChannel
```

### ❌ ERROR #2: PhaseManager sin TimerService
```php
// ❌ MAL - PhaseManager no funciona sin TimerService
$phaseManager = PhaseManager::fromArray($state);

// ✅ BIEN - SIEMPRE conectar TimerService
$phaseManager = PhaseManager::fromArray($state);
$timerService = $this->getTimerService($match);
$phaseManager->setTimerService($timerService);
```

### ❌ ERROR #3: Nombres de eventos inconsistentes
```php
// capabilities.json
"PlayerDisconnectedEvent": {
    "name": "player.disconnected"  // ❌ MAL
}

// ✅ BIEN - Debe coincidir exactamente con broadcastAs()
"PlayerDisconnectedEvent": {
    "name": "game.player.disconnected"
}
```

### ❌ ERROR #4: Falta @stack('scripts')
```blade
{{-- ❌ MAL - El popup de desconexión no carga su JS --}}
</body>
</html>

{{-- ✅ BIEN - SIEMPRE incluir antes de </body> --}}
@stack('scripts')
</body>
</html>
```

### ❌ ERROR #5: Nombres de métodos del Engine
```php
// ❌ MAL - BaseGameEngine no encuentra el método
public function handlePlayerDisconnect() { }

// ✅ BIEN - Usar convención "on" + EventName
public function onPlayerDisconnected() { }
public function onPlayerReconnected() { }
public function onTimerExpired() { }
```

---

## 📋 TEMPLATES OBLIGATORIOS

### Template: Event Class
```php
<?php
namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;  // ← SIEMPRE PresenceChannel
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;  // ← SIEMPRE ShouldBroadcastNow
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * [Descripción del evento]
 */
class CustomEvent implements ShouldBroadcastNow  // ← CRÍTICO
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public array $data;

    public function __construct(GameMatch $match, array $data = [])
    {
        $this->roomCode = $match->room->code;
        $this->data = $data;
    }

    public function broadcastOn(): PresenceChannel  // ← CRÍTICO
    {
        return new PresenceChannel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        // Convención: game.{category}.{action}
        return 'game.custom.event';
    }

    public function broadcastWith(): array
    {
        return $this->data;
    }
}
```

### Template: game.blade.php
```blade
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $room->game->name }} - {{ $code }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-900 text-white min-h-screen">
    <div id="app" class="container mx-auto px-4 py-8">
        {{-- Game UI --}}
    </div>

    {{-- ✅ CRÍTICO: SIEMPRE incluir popup de desconexión --}}
    <x-game.player-disconnected-popup />

    @vite(['resources/js/app.js', 'games/{slug}/js/{GameName}GameClient.js'])

    <script type="module">
        const config = {
            roomCode: '{{ $code }}',
            matchId: {{ $match->id }},
            playerId: {{ $playerId }},
            userId: {{ $userId }},
            gameSlug: '{slug}',
            players: [],
            scores: {},
            eventConfig: @json($eventConfig),
        };

        const gameClient = new window.{GameName}GameClient(config);

        // ✅ Cargar estado inicial ANTES de conectar WebSockets
        (async () => {
            try {
                const response = await fetch(`/api/rooms/{{ $code }}/state`);
                if (response.ok) {
                    const data = await response.json();
                    const gameState = data.game_state;

                    if (gameState) {
                        console.log('[{GameName}] Loading initial state:', gameState);
                        gameClient.restoreGameState(gameState);
                    }
                } else {
                    console.warn('⚠️ [{GameName}] Could not load initial state');
                }
            } catch (error) {
                console.error('❌ [{GameName}] Error loading initial state:', error);
            }

            // Configurar Event Manager DESPUÉS de cargar el estado inicial
            gameClient.setupEventManager();
        })();
    </script>

    {{-- ✅ CRÍTICO: SIEMPRE incluir @stack('scripts') --}}
    @stack('scripts')
</body>
</html>
```

### Template: capabilities.json
```json
{
  "slug": "{slug}",
  "version": "1.0",
  "requires": {
    "websockets": true,
    "turns": true,
    "scoring": true,
    "timers": true
  },
  "provides": {
    "events": [
      "CustomEvent"
    ],
    "routes": [],
    "views": [
      "games/{slug}/game"
    ]
  },
  "event_config": {
    "channel": "room.{roomCode}",
    "events": {
      "RoundStartedEvent": {
        "name": "game.round.started",
        "description": "Sistema: Nueva ronda iniciada",
        "handler": "handleRoundStarted"
      },
      "RoundEndedEvent": {
        "name": "game.round.ended",
        "description": "Sistema: Ronda finalizada con resultados",
        "handler": "handleRoundEnded"
      },
      "GameStateUpdatedEvent": {
        "name": "game.state.updated",
        "description": "Broadcast cambios de estado del juego",
        "handler": "handleGameStateUpdated"
      },
      "PlayersUnlockedEvent": {
        "name": "game.players.unlocked",
        "description": "Broadcast cuando todos los jugadores son desbloqueados",
        "handler": "handlePlayersUnlocked"
      },
      "PlayerScoreUpdatedEvent": {
        "name": "player.score.updated",
        "description": "Broadcast cuando cambia la puntuación de un jugador",
        "handler": "handlePlayerScoreUpdated"
      },
      "TimerUpdatedEvent": {
        "name": "timer.updated",
        "description": "Actualización del temporizador cada segundo",
        "handler": "handleTimerUpdate"
      },
      "PlayerDisconnectedEvent": {
        "name": "game.player.disconnected",
        "description": "Sistema: Jugador desconectado",
        "handler": "handlePlayerDisconnected"
      },
      "PlayerReconnectedEvent": {
        "name": "game.player.reconnected",
        "description": "Sistema: Jugador reconectado",
        "handler": "handlePlayerReconnected"
      }
    }
  }
}
```

---

## 📚 Arquitectura Actualizada

### Módulos del Sistema (14 configurables)

**Core (siempre activos):**
- `game_core` - Ciclo de vida del juego
- `room_manager` - Gestión de salas

**Opcionales:**
- `guest_system` - Invitados sin registro
- `turn_system` - Turnos (sequential/simultaneous/free)
- `scoring_system` - Puntuación y ranking
- `teams_system` - Agrupación en equipos
- `timer_system` - Temporizadores con auto-advance
- `roles_system` - Roles específicos del juego
- `card_deck_system` - Gestión de mazos
- `board_grid_system` - Tableros de juego
- `spectator_mode` - Observadores
- `ai_players` - Bots/IA
- `replay_history` - Grabación de partidas
- `real_time_sync` - WebSockets (Laravel Reverb)

### Convención de Nombres de Eventos

Formato: `{category}.{subcategory}.{action}`

**Categorías estándar:**
```
game.round.*      (started, ended)
game.phase.*      (changed)
game.turn.*       (changed)
game.player.*     (disconnected, reconnected, eliminated)
game.state.*      (updated)
game.players.*    (unlocked)
player.score.*    (updated)
player.action.*   (submitted)
timer.*           (updated, expired)
```

**⚠️ IMPORTANTE**: Los nombres en capabilities.json DEBEN coincidir exactamente con `broadcastAs()` del evento PHP.

---

## 🚀 PROCESO POR FASES (Nuevo)

### FASE 1: Análisis y Configuración
**Objetivo**: Entender el juego y definir arquitectura

**Pasos:**
1. Leer archivo de descripción (si existe)
2. Analizar y extraer información
3. Inferir módulos necesarios
4. Identificar ambigüedades
5. Hacer preguntas SOLO sobre lo ambiguo

**Checkpoint 1:**
```
✅ Verificar:
- Nombre del juego claro
- Slug válido (lowercase, guiones)
- Módulos identificados correctamente
- Configuración completa (sin "TODO" o "???")
- Respuestas del usuario claras
```

**Output**: Configuración JSON completa

---

### FASE 2: Estructura Base
**Objetivo**: Crear archivos de configuración

**Generar:**
1. `games/{slug}/config.json` (con módulos y timing)
2. `games/{slug}/capabilities.json` (con event_config completo)
3. `games/{slug}/README.md` (descripción básica)

**Checkpoint 2:**
```
✅ Verificar config.json:
- JSON válido (ejecutar: jq . config.json)
- Módulos tienen "enabled": true/false
- timing.round_ended configurado con auto_next
- Todos los campos requeridos presentes

✅ Verificar capabilities.json:
- JSON válido
- event_config.channel = "room.{roomCode}"
- TODOS los eventos base incluidos (RoundStarted, RoundEnded, etc.)
- Nombres siguen convención game.*.*
- Cada evento tiene: name, description, handler
```

**Mostrar al usuario:**
```
📂 Estructura Base Generada:
✅ config.json (módulos configurados)
✅ capabilities.json (eventos mapeados)
✅ README.md

⏳ Esperando confirmación antes de continuar...
```

---

### FASE 3: Backend - ScoreCalculator
**Objetivo**: Sistema de puntuación

**Generar:**
1. `games/{slug}/{GameName}ScoreCalculator.php`

**Template aplicado:**
```php
<?php

namespace Games\{Slug};

use App\Services\Modules\ScoringSystem\ScoreCalculatorInterface;

class {GameName}ScoreCalculator implements ScoreCalculatorInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            // Valores por defecto desde config.json
        ], $config);
    }

    public function calculate(string $reason, array $context = []): int
    {
        return match($reason) {
            'correct_answer' => $this->calculateCorrectAnswer($context),
            'speed_bonus' => $this->calculateSpeedBonus($context),
            // ... más reasons
            default => 0,
        };
    }

    // Métodos privados para cálculos específicos
}
```

**Checkpoint 3:**
```
✅ Verificar ScoreCalculator:
- Sintaxis PHP válida (php -l)
- Implementa ScoreCalculatorInterface
- Constructor recibe array config
- Método calculate() implementado con match()
- Todos los reasons del juego cubiertos
- Usa valores de config, no hardcoded
```

**Mostrar:**
```
✅ ScoreCalculator generado

📊 Reasons implementados:
- correct_answer: 10 pts
- speed_bonus: +5 pts
- ...

⏳ Esperando confirmación...
```

---

### FASE 4: Backend - GameEngine (Parte 1: Estructura)
**Objetivo**: Crear estructura del Engine sin lógica compleja

**Generar:**
1. `games/{slug}/{GameName}Engine.php` (solo estructura y métodos básicos)

**Incluir:**
```php
<?php

namespace Games\{Slug};

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

class {GameName}Engine extends BaseGameEngine
{
    protected {GameName}ScoreCalculator $scoreCalculator;

    public function __construct()
    {
        $gameConfig = $this->getGameConfig(); // ← Heredado
        $scoringConfig = $gameConfig['scoring'] ?? [];
        $this->scoreCalculator = new {GameName}ScoreCalculator($scoringConfig);
    }

    public function initialize(GameMatch $match): void
    {
        // TODO: Implementar en siguiente fase
    }

    protected function onGameStart(GameMatch $match): void
    {
        // TODO: Implementar en siguiente fase
    }

    protected function startNewRound(GameMatch $match): void
    {
        // TODO: Implementar en siguiente fase
    }

    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        // TODO: Implementar en siguiente fase
    }

    public function endCurrentRound(GameMatch $match): void
    {
        // TODO: Implementar en siguiente fase
    }

    protected function filterGameStateForBroadcast(array $gameState, GameMatch $match): array
    {
        // TODO: Implementar en siguiente fase
        return $gameState;
    }

    public function checkWinCondition(GameMatch $match): ?Player
    {
        return null; // Por defecto
    }

    public function getGameStateForPlayer(GameMatch $match, Player $player): array
    {
        return [
            'phase' => $match->game_state['phase'] ?? 'unknown',
            'message' => 'El juego ha empezado',
        ];
    }

    // ✅ Si usa PhaseManager, añadir métodos helper
    // ✅ Si necesita comportamiento especial en desconexión, añadir onPlayerDisconnected/Reconnected
}
```

**Checkpoint 4:**
```
✅ Verificar Engine estructura:
- Sintaxis PHP válida (php -l)
- Extiende BaseGameEngine
- scoreCalculator como propiedad de clase
- Constructor inicializa scoreCalculator
- TODOS los métodos abstractos implementados (aunque sean TODO)
- NO duplica getGameConfig() ni getFinalScores()
- namespace correcto: Games\{Slug}
```

**Mostrar:**
```
✅ {GameName}Engine (estructura) generado

📝 Métodos pendientes de implementar:
- initialize()
- onGameStart()
- startNewRound()
- processRoundAction()
- endCurrentRound()
- filterGameStateForBroadcast()

⏳ Esperando confirmación...
```

---

### FASE 5: Backend - GameEngine (Parte 2: initialize)
**Objetivo**: Implementar inicialización del juego

**Implementar en `initialize()`:**
1. Cargar datos del juego (preguntas, cartas, etc.)
2. Configurar game_state inicial
3. Cachear players
4. Inicializar módulos con `initializeModules()`
5. **CRÍTICO**: Inicializar PlayerManager correctamente

**Template:**
```php
public function initialize(GameMatch $match): void
{
    // 1. Cargar datos específicos del juego
    $questions = $this->loadQuestions($match); // O loadCards(), etc.

    // 2. Config inicial
    $match->game_state = [
        '_config' => [
            'game' => '{slug}',
            'initialized_at' => now()->toDateTimeString(),
            // Más configuración
        ],
        'phase' => 'waiting',
        'questions' => $questions,
        'current_question' => null,
    ];
    $match->save();

    // 3. Cachear players
    $this->cachePlayersInState($match);

    // 4. Inicializar módulos
    $this->initializeModules($match, [
        'scoring_system' => [
            'calculator' => $this->scoreCalculator
        ],
        'round_system' => [
            'total_rounds' => count($questions)
        ]
        // Más módulos según config.json
    ]);

    // 5. ✅ CRÍTICO: Inicializar PlayerManager
    $playerIds = $match->players->pluck('id')->toArray();
    $playerManager = new \App\Services\Modules\PlayerSystem\PlayerManager(
        $playerIds,
        $this->scoreCalculator,
        [
            'available_roles' => [], // ['drawer', 'guesser'] si usa roles
            'allow_multiple_persistent_roles' => false,
            'track_score_history' => false,
        ]
    );
    $this->savePlayerManager($match, $playerManager);
}
```

**Checkpoint 5:**
```
✅ Verificar initialize():
- Sintaxis PHP válida
- Carga datos del juego (questions, cards, etc.)
- Configura game_state con phase: 'waiting'
- Llama a cachePlayersInState()
- Llama a initializeModules() con scoreCalculator
- Inicializa PlayerManager (NO PlayerStateManager)
- Llama a savePlayerManager()
- NO usa PlayerStateManager en ninguna parte
```

**Mostrar:**
```
✅ initialize() implementado

📦 Inicializa:
- Game state con phase: 'waiting'
- {N} preguntas/cartas cargadas
- PlayerManager con {scoreCalculator}
- Módulos: scoring, round, turn, timer

⏳ Esperando confirmación...
```

---

### FASE 6: Backend - GameEngine (Parte 3: startNewRound)
**Objetivo**: Implementar inicio de ronda siguiendo el protocolo

**Template:**
```php
protected function startNewRound(GameMatch $match): void
{
    // ✅ PASO 1: Reset locks (emite PlayersUnlockedEvent automáticamente)
    $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
    $playerManager->reset($match);
    $this->savePlayerManager($match, $playerManager); // ← CRÍTICO

    // ✅ PASO 2: Lógica específica del juego
    $question = $this->loadNextQuestion($match);

    // Si usa roles
    $this->assignRoles($match);

    // Si usa PhaseManager (múltiples fases por ronda)
    // $this->startPhases($match);

    // El timer ya se inició automáticamente por handleNewRound()
}
```

**Checkpoint 6:**
```
✅ Verificar startNewRound():
- Sintaxis PHP válida
- PRIMERO: getPlayerManager()
- SEGUNDO: reset() con $match
- TERCERO: savePlayerManager() inmediatamente después
- Carga siguiente pregunta/carta/dato
- Asigna roles si el juego los usa
- NO inicia timer manualmente (lo hace handleNewRound)
- Si usa PhaseManager, conecta TimerService
```

**Mostrar:**
```
✅ startNewRound() implementado

🔄 Flujo:
1. Reset PlayerManager
2. Carga siguiente elemento del juego
3. {Asigna roles si aplica}
4. {Inicia fases si aplica}

⏳ Esperando confirmación...
```

---

### FASE 7: Backend - GameEngine (Parte 4: processRoundAction)
**Objetivo**: Procesar acciones de jugadores

**Template:**
```php
protected function processRoundAction(GameMatch $match, Player $player, array $data): array
{
    $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);

    // Verificar si ya respondió/actuó
    if ($playerManager->isPlayerLocked($player->id)) {
        return ['success' => false, 'message' => 'Ya has respondido'];
    }

    // Validar y procesar acción
    $result = $this->validatePlayerAction($match, $player, $data);

    if ($result['is_correct']) {
        // Otorgar puntos (emite PlayerScoreUpdatedEvent automáticamente)
        $context = ['time_taken' => $this->getElapsedTime($match, 'round')];
        $points = $playerManager->awardPoints($player->id, 'correct_answer', $context, $match);
    }

    // Bloquear jugador (registra acción)
    $playerManager->lockPlayer($player->id, $match, $player, $result);
    $this->savePlayerManager($match, $playerManager);

    // Decidir si terminar ronda
    $forceEnd = false;
    if ($result['is_correct'] && $gameMode === 'first_wins') {
        $forceEnd = true;
    }

    return [
        'success' => true,
        'result' => $result,
        'force_end' => $forceEnd,
    ];
}
```

**Checkpoint 7:**
```
✅ Verificar processRoundAction():
- Sintaxis PHP válida
- Obtiene PlayerManager al inicio
- Verifica isPlayerLocked() antes de procesar
- Usa awardPoints() para dar puntos (NO modificar scores manualmente)
- Usa lockPlayer() para bloquear (NO modificar locks manualmente)
- Llama a savePlayerManager() después de cambios
- Retorna success + result + force_end
```

**Mostrar:**
```
✅ processRoundAction() implementado

⚙️ Lógica:
1. Verifica lock
2. Valida acción
3. Otorga puntos si correcto
4. Bloquea jugador
5. Decide si forzar fin de ronda

⏳ Esperando confirmación...
```

---

### FASE 8: Backend - GameEngine (Parte 5: endCurrentRound y filter)
**Objetivo**: Finalizar ronda y filtrar información sensible

**Template:**
```php
public function endCurrentRound(GameMatch $match): void
{
    // Obtener resultados
    $results = $this->getAllPlayerResults($match);

    // Delegar al base (emite RoundEndedEvent automáticamente)
    $this->completeRound($match, $results);
}

protected function getAllPlayerResults(GameMatch $match): array
{
    $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
    $allActions = $playerManager->getAllActions();
    $currentQuestion = $this->getCurrentQuestion($match);

    return [
        'question' => $currentQuestion,
        'correct_answer' => $currentQuestion['correct_answer'],
        'players' => $allActions,
    ];
}

protected function filterGameStateForBroadcast(array $gameState, GameMatch $match): array
{
    $filtered = $gameState;

    // Remover información sensible que no todos deben ver
    if (isset($filtered['current_question']['correct_answer'])) {
        unset($filtered['current_question']['correct_answer']);
    }

    // Si usa roles, remover info privada
    if (isset($filtered['drawer_word'])) {
        unset($filtered['drawer_word']);
    }

    return $filtered;
}
```

**Checkpoint 8:**
```
✅ Verificar endCurrentRound():
- Sintaxis PHP válida
- Llama a getAllPlayerResults()
- Llama a completeRound() (NO emite RoundEndedEvent manualmente)
- NO modifica scores manualmente

✅ Verificar filterGameStateForBroadcast():
- Remueve respuestas correctas
- Remueve información privada de roles
- Remueve datos sensibles del juego
- NO modifica el game_state original (trabaja sobre copia)
```

**Mostrar:**
```
✅ endCurrentRound() y filterGameStateForBroadcast() implementados

🔒 Información filtrada en broadcasts:
- Respuestas correctas (se muestran solo al terminar ronda)
- {Información privada de roles}
- {Otros datos sensibles}

⏳ Esperando confirmación...
```

---

### FASE 9: Backend - Eventos Personalizados (Si Aplica)
**Objetivo**: Crear eventos específicos del juego

**Solo si el juego necesita eventos personalizados** (como WordRevealedEvent en Pictionary)

**Generar** (usando template obligatorio):
1. `app/Events/Game/{CustomEvent}.php`

**Checkpoint 9:**
```
✅ Verificar cada evento:
- Usa ShouldBroadcastNow (NO ShouldBroadcast)
- Usa PresenceChannel (NO Channel)
- broadcastAs() sigue convención game.*.*
- Nombre en capabilities.json coincide exactamente
- Si es evento privado, documenta channel_name en capabilities.json
```

**Mostrar:**
```
✅ Eventos personalizados generados:
- {CustomEvent1}: game.custom.event1
- {CustomEvent2}: game.custom.event2

⚙️ Configuración en capabilities.json actualizada

⏳ Esperando confirmación...
```

---

### FASE 10: Frontend - GameClient (Estructura)
**Objetivo**: Crear cliente JavaScript básico

**Generar:**
1. `games/{slug}/js/{GameName}GameClient.js`

**Template:**
```javascript
import { BaseGameClient } from '/resources/js/core/BaseGameClient.js';

class {GameName}GameClient extends BaseGameClient {
    constructor(config) {
        super(config);

        // Estado específico del juego
        this.currentQuestion = null;
        this.myAnswer = null;

        // Inicializar event listeners de botones
        this.initializeEventListeners();
    }

    /**
     * Override: Configurar EventManager con handlers específicos
     */
    setupEventManager() {
        console.log('[{GameName}] ===== SETTING UP EVENT MANAGER =====');
        console.log('[{GameName}] EventConfig:', this.eventConfig);

        // Registrar handlers personalizados si hay eventos custom
        const customHandlers = {
            // handleCustomEvent: (event) => this.handleCustomEvent(event),
        };

        console.log('[{GameName}] Custom handlers:', Object.keys(customHandlers));

        // Llamar al setupEventManager del padre
        super.setupEventManager(customHandlers);

        console.log('[{GameName}] ===== EVENT MANAGER SETUP COMPLETE =====');
    }

    /**
     * Initialize DOM event listeners
     */
    initializeEventListeners() {
        // TODO: Añadir listeners de botones
    }

    /**
     * Override: Manejar inicio de ronda
     */
    handleRoundStarted(event) {
        console.log('[{GameName}] Round started:', event);

        // ✅ IMPORTANTE: Llamar al padre (inicia timer automáticamente)
        super.handleRoundStarted(event);

        // Lógica específica del juego
        this.currentQuestion = event.game_state.current_question;
        this.renderQuestion(this.currentQuestion);
    }

    /**
     * Override: Manejar fin de ronda
     */
    handleRoundEnded(event) {
        console.log('[{GameName}] Round ended:', event);

        // ✅ Llamar al padre (muestra resultados + countdown)
        super.handleRoundEnded(event);

        // Mostrar resultados específicos
        this.showResults(event.results);
    }

    /**
     * Override: Manejar desbloqueo de jugadores
     */
    handlePlayersUnlocked(event) {
        console.log('[{GameName}] Players unlocked');
        this.isLocked = false;
        this.resetUI();
    }

    /**
     * Restaurar estado del juego (para F5/refresh)
     */
    restoreGameState(gameState) {
        console.log('[{GameName}] Restoring game state:', gameState);

        if (gameState.phase === 'playing') {
            // Restaurar UI de juego activo
            this.currentQuestion = gameState.current_question;
            this.renderQuestion(this.currentQuestion);

            // Restaurar locks
            const playerManager = gameState.player_system;
            if (playerManager?.players?.[this.playerId]?.locked) {
                this.isLocked = true;
                this.showLockedUI();
            }
        }
    }

    // ✅ CRÍTICO: Implementar estos métodos
    getTimerElement() {
        return document.getElementById('round-timer');
    }

    getCountdownElement() {
        return document.getElementById('next-round-countdown');
    }

    // TODO: Implementar métodos específicos del juego
    renderQuestion(question) { }
    showResults(results) { }
    resetUI() { }
    showLockedUI() { }
}

// Export para uso global
if (typeof window !== 'undefined') {
    window.{GameName}GameClient = {GameName}GameClient;
}

export default {GameName}GameClient;
```

**Checkpoint 10:**
```
✅ Verificar GameClient:
- Sintaxis JavaScript válida (node --check)
- Extiende BaseGameClient
- Constructor llama a super(config)
- setupEventManager() registra handlers custom
- handleRoundStarted() llama a super.handleRoundStarted()
- handleRoundEnded() llama a super.handleRoundEnded()
- handlePlayersUnlocked() implementado
- restoreGameState() implementado para F5
- getTimerElement() y getCountdownElement() implementados
- window.{GameName}GameClient exportado
```

**Mostrar:**
```
✅ {GameName}GameClient generado

📱 Handlers implementados:
- handleRoundStarted() (con super)
- handleRoundEnded() (con super)
- handlePlayersUnlocked()
- restoreGameState()

⏳ Esperando confirmación...
```

---

### FASE 11: Frontend - Vista Blade
**Objetivo**: Crear interfaz del juego

**Generar:**
1. `games/{slug}/views/game.blade.php` (usando template obligatorio)

**Incluir SIEMPRE:**
- `<x-game.player-disconnected-popup />`
- `@stack('scripts')` antes de `</body>`
- Lógica de restauración con `restoreGameState()`

**Checkpoint 11:**
```
✅ Verificar game.blade.php:
- Incluye <x-game.player-disconnected-popup />
- Incluye @stack('scripts') antes de </body>
- Carga estado inicial con fetch('/api/rooms/{code}/state')
- Llama a restoreGameState() ANTES de setupEventManager()
- Configura eventConfig desde @json($eventConfig)
- Variables PHP escapadas correctamente
```

**Mostrar:**
```
✅ game.blade.php generado

✅ Características incluidas:
- Popup de desconexión
- Restauración de estado (F5)
- Timer visual
- Countdown automático
- Event Manager configurado

⏳ Esperando confirmación...
```

---

### FASE 12: Verificación Final y Registro
**Objetivo**: Validar todo y registrar el juego

**Ejecutar verificaciones automáticas:**

```bash
# 1. Validar sintaxis PHP
php -l games/{slug}/*.php

# 2. Validar JSON
jq . games/{slug}/config.json
jq . games/{slug}/capabilities.json

# 3. Validar JavaScript
node --check games/{slug}/js/*.js

# 4. Verificar que nombres de eventos coincidan
# (comparar capabilities.json con Event::broadcastAs())
```

**Checklist Final Completo:**

```
BACKEND:
☐ PlayerManager inicializado (NO PlayerStateManager)
☐ scoreCalculator como propiedad de clase
☐ startNewRound() incluye reset() + savePlayerManager()
☐ processRoundAction() usa awardPoints() y lockPlayer()
☐ endCurrentRound() llama a completeRound()
☐ filterGameStateForBroadcast() implementado
☐ NO duplica getGameConfig() ni getFinalScores()
☐ Eventos usan ShouldBroadcastNow + PresenceChannel
☐ Nombres de eventos coinciden con capabilities.json
☐ onPlayerDisconnected/Reconnected si necesario
☐ Si usa PhaseManager, conecta TimerService

FRONTEND:
☐ GameClient hereda de BaseGameClient
☐ Constructor llama a super(config)
☐ handleRoundStarted() llama a super
☐ handleRoundEnded() llama a super
☐ handlePlayersUnlocked() implementado
☐ restoreGameState() implementado
☐ getTimerElement() implementado
☐ getCountdownElement() implementado
☐ game.blade.php incluye popup de desconexión
☐ game.blade.php incluye @stack('scripts')
☐ Carga estado inicial antes de setupEventManager()

CONFIGURACIÓN:
☐ config.json válido
☐ capabilities.json válido
☐ event_config completo con TODOS los eventos base
☐ timing.round_ended con auto_next configurado
☐ Todos los módulos necesarios habilitados

VALIDACIÓN:
☐ Sintaxis PHP válida (php -l)
☐ Sintaxis JS válida (node --check)
☐ JSON válido (jq)
☐ Nombres de eventos consistentes
```

**Registrar el juego:**

```bash
# Registrar en la base de datos
php artisan game:register {slug}
```

**Mostrar Output Final:**

```
✨ ¡Juego "{Game Name}" creado con éxito!

📂 Estructura generada:
✅ games/{slug}/{GameName}Engine.php
✅ games/{slug}/{GameName}ScoreCalculator.php
✅ games/{slug}/config.json
✅ games/{slug}/capabilities.json
✅ games/{slug}/views/game.blade.php
✅ games/{slug}/js/{GameName}GameClient.js
✅ games/{slug}/README.md

🎮 Arquitectura Aplicada:
✅ PlayerManager unificado (scores + state + roles)
✅ Round Lifecycle Protocol completo
✅ filterGameStateForBroadcast() para seguridad
✅ Hereda getGameConfig() y getFinalScores()
✅ Protocolo de Refresh/Reconexión (F5)
✅ Protocolo de Desconexión/Reconexión
✅ Auto-next con timing configurado
✅ Broadcasting correcto (ShouldBroadcastNow + PresenceChannel)
✅ Nombres de eventos consistentes

🔧 Módulos Configurados:
{Lista de módulos con sus configuraciones}

📋 Siguiente paso:

El juego está listo para probar:
1. php artisan serve
2. npm run dev
3. Navega a /games/{slug}

📚 Documentación de referencia:
- games/pictionary/ - Roles, canvas, claim pattern
- games/trivia/ - Sin roles, simultaneous, speed bonus

🎉 ¡Listo para jugar!
```

---

## 🔧 Sistema Unificado de Fases y Timers (CRÍTICO)

### 📖 Contexto: Problema Detectado

Después de implementar varios juegos, detectamos inconsistencias en cómo se manejaban los timers:
- Algunos usaban `RoundManager` directamente
- Otros usaban `PhaseManager`
- El código de deserialización no detectaba correctamente qué manager reconstruir
- Los timers no se sincronizaban correctamente al refrescar la página

### ✅ Solución: Sistema Unificado de Fases

**DECISIÓN ARQUITECTÓNICA: TODOS los juegos SIEMPRE usan fases (mínimo 1)**

#### Jerarquía de Módulos
```
RoundManager
└── TurnManager
    └── PhaseManager (extiende TurnManager)
```

#### Tipos de Juegos

**Juegos Single-Fase** (Trivia, Pictionary):
- Una única fase `main` por ronda
- Timer de fase = timer de ronda
- Ejemplo: 30 segundos para responder pregunta

**Juegos Multi-Fase** (Mentiroso, futuro Werewolf):
- Múltiples fases secuenciales por ronda
- Cada fase tiene su propio timer
- Ejemplo: `preparation` (10s) → `persuasion` (30s) → `voting` (15s)

### 🏗️ Implementación en Backend

#### 1. Factory Method en RoundManager

```php
// app/Services/Modules/RoundSystem/RoundManager.php

public static function createFromConfig(array $config, array $playerIds, int $totalRounds): self
{
    $phases = self::extractPhasesFromConfig($config);
    $phaseManager = new PhaseManager($phases);

    return new self(
        turnManager: $phaseManager,
        totalRounds: $totalRounds,
        currentRound: 1
    );
}

protected static function extractPhasesFromConfig(array $config): array
{
    $timing = $config['timing'] ?? [];
    $phases = [];

    // Buscar fases explícitas en timing (multi-fase)
    foreach ($timing as $key => $phaseConfig) {
        if (!in_array($key, ['game_start', 'round_start', 'round_ended', 'results', 'countdown_warning_threshold'])) {
            if (isset($phaseConfig['duration'])) {
                $phases[] = ['name' => $key, 'duration' => $phaseConfig['duration']];
            }
        }
    }

    // Si no hay fases explícitas, crear fase única "main" (single-fase)
    if (count($phases) === 0) {
        $duration = $config['modules']['timer_system']['round_duration']
            ?? $config['modules']['turn_system']['time_limit']
            ?? 30;

        return [['name' => 'main', 'duration' => $duration]];
    }

    return $phases;
}
```

#### 2. Deserialización Correcta

```php
// RoundManager::fromArray() - Detectar PhaseManager vs TurnManager

if (isset($data['turn_system'])) {
    // Detectar por presencia de 'phases' key
    if (isset($data['turn_system']['phases'])) {
        $turnManager = PhaseManager::fromArray($data['turn_system']);
    } else {
        $turnManager = TurnManager::fromArray($data['turn_system']);
    }

    // ✅ CRÍTICO: Conectar TimerService al manager
    if (isset($data['timer_system'])) {
        $timerService = \App\Services\Modules\TimerSystem\TimerService::fromArray($data);
        $turnManager->setTimerService($timerService);
    }
}
```

#### 3. Emitir PhaseChangedEvent (NO RoundStartedEvent con timing)

```php
// games/{slug}/{GameName}Engine.php

use App\Events\Game\PhaseChangedEvent;

protected function getRoundStartTiming(GameMatch $match): ?array
{
    return null;  // ✅ NO emitir timing en RoundStartedEvent
}

protected function onRoundStarted(GameMatch $match, int $currentRound, int $totalRounds): void
{
    $roundManager = $this->getRoundManager($match);
    $phaseManager = $roundManager->getTurnManager(); // Es PhaseManager

    $currentPhase = $phaseManager->getCurrentPhaseName();
    $timingInfo = $phaseManager->getTimingInfo();

    $timing = [
        'server_time' => now()->timestamp,
        'duration' => $timingInfo['delay'] ?? 0
    ];

    // ✅ Emitir PhaseChangedEvent DESPUÉS de RoundStartedEvent
    event(new PhaseChangedEvent(
        match: $match,
        newPhase: $currentPhase,
        previousPhase: '',
        additionalData: $timing
    ));
}
```

### 🎨 Implementación en Frontend

#### 1. BaseGameClient maneja PhaseChangedEvent

```javascript
// resources/js/core/BaseGameClient.js

handleRoundStarted(event) {
    // ✅ YA NO inicia timer aquí
    this.currentRound = event.current_round;
    this.totalRounds = event.total_rounds;

    // Timer se iniciará cuando llegue PhaseChangedEvent
}

handlePhaseChanged(event) {
    console.log('🎯 [BaseGameClient] handlePhaseChanged', event);

    // Iniciar timer de fase si viene timing metadata
    if (event.additional_data?.server_time && event.additional_data?.duration) {
        const timerElement = this.getTimerElement();

        if (timerElement) {
            const durationMs = event.additional_data.duration * 1000;
            const timerName = `phase_${event.new_phase}`;

            this.timing.startServerSyncedCountdown(
                event.additional_data.server_time,
                durationMs,
                timerElement,
                () => this.onPhaseTimerExpired(event.new_phase),
                timerName
            );
        }
    }
}

async onPhaseTimerExpired(phaseName) {
    console.log(`⏰ [BaseGameClient] Phase timer expired: ${phaseName}`);

    const timerElement = this.getTimerElement();
    if (timerElement) {
        timerElement.textContent = '¡Tiempo agotado!';
        timerElement.classList.add('timer-expired');
    }

    // Notificar al backend para avanzar ronda
    await fetch(`/api/rooms/${this.roomCode}/check-timer`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
        },
        body: JSON.stringify({
            phase: phaseName,
            timestamp: Date.now()
        })
    });
}
```

#### 2. Estrategia de Refresh/Reconexión

**Problema:** WebSocket se desconecta/reconecta durante F5, perdiendo eventos.

**Solución:** Fetch estado inicial ANTES de conectar WebSockets.

```javascript
// games/{slug}/views/game.blade.php

(async () => {
    try {
        const response = await fetch(`/api/rooms/{{ $code }}/state`);
        if (response.ok) {
            const data = await response.json();
            const gameState = data.game_state;

            // 1️⃣ Prioridad: Si el juego terminó
            if (gameState?.phase === 'finished') {
                triviaClient.showElement('finished-state');
                triviaClient.renderPodium(gameState.ranking, gameState.final_scores);
                return;
            }

            // 2️⃣ Si hay una pregunta activa, mostrarla
            if (gameState?.current_question) {
                triviaClient.handleRoundStarted({
                    current_round: gameState.round_system?.current_round || 1,
                    total_rounds: gameState.round_system?.total_rounds || 10,
                    game_state: gameState
                });

                // 3️⃣ Reconstruir y reiniciar timer si existe
                const timerData = gameState.timer_system?.timers?.round;
                if (timerData) {
                    const startedAt = new Date(timerData.started_at).getTime() / 1000;
                    const duration = timerData.duration;

                    const phaseEvent = {
                        new_phase: 'main',
                        previous_phase: '',
                        additional_data: {
                            server_time: startedAt,
                            duration: duration
                        }
                    };

                    setTimeout(() => {
                        triviaClient.handlePhaseChanged(phaseEvent);
                    }, 100);
                }

                // 4️⃣ Restaurar locks
                const locks = gameState.player_state_system?.locks || {};
                if (locks[config.playerId] === true) {
                    triviaClient.hasAnswered = true;
                    triviaClient.showElement('locked-overlay');
                }
            }
        }
    } catch (error) {
        console.error('❌ Error loading initial state:', error);
    }

    // Configurar Event Manager DESPUÉS de cargar el estado
    triviaClient.setupEventManager();
})();
```

### 📋 Checklist para Juegos Nuevos

#### Backend:
```
☐ initializeModules() usa RoundManager::createFromConfig()
☐ getRoundStartTiming() retorna null
☐ onRoundStarted() emite PhaseChangedEvent con timing
☐ Si multi-fase: config timing tiene múltiples fases
☐ Si single-fase: config timing tiene solo round_duration
☐ PhaseManager conectado con TimerService en deserialization
```

#### Frontend:
```
☐ handleRoundStarted() NO inicia timer
☐ handlePhaseChanged() implementado para iniciar timer
☐ onPhaseTimerExpired() notifica backend
☐ game.blade.php fetch estado inicial antes de setupEventManager()
☐ Reconstruye timer desde timerData del backend
☐ capabilities.json incluye PhaseChangedEvent
```

#### Config:
```json
// Juego SINGLE-FASE (Trivia, Pictionary)
{
  "modules": {
    "timer_system": {
      "enabled": true,
      "round_duration": 30  // ← Timer de fase única
    }
  }
}

// Juego MULTI-FASE (Mentiroso)
{
  "timing": {
    "preparation": {
      "duration": 10,
      "description": "Preparar respuesta"
    },
    "persuasion": {
      "duration": 30,
      "description": "Convencer a otros"
    },
    "voting": {
      "duration": 15,
      "description": "Votar"
    }
  }
}
```

### 🎯 Flujo Completo

**Backend:**
```
1. RoundManager::createFromConfig()
   → Detecta fases desde config
   → Crea PhaseManager (siempre)

2. RoundStartedEvent emitido (sin timing)

3. onRoundStarted() hook
   → PhaseManager.getCurrentPhaseName()
   → PhaseManager.getTimingInfo()
   → PhaseChangedEvent emitido (con timing)
```

**Frontend:**
```
1. EventManager recibe RoundStartedEvent
   → handleRoundStarted() actualiza UI
   → NO inicia timer

2. EventManager recibe PhaseChangedEvent
   → handlePhaseChanged() inicia timer
   → TimingModule countdown sincronizado con servidor

3. Timer llega a 0
   → onPhaseTimerExpired() POST /check-timer
   → Backend valida y avanza ronda/fase
```

### 🐛 Errores Comunes EVITADOS

❌ **Error 1**: Iniciar timer en `handleRoundStarted()`
✅ **Correcto**: Iniciar timer en `handlePhaseChanged()`

❌ **Error 2**: Emitir timing en `RoundStartedEvent`
✅ **Correcto**: Emitir timing en `PhaseChangedEvent`

❌ **Error 3**: `fromArray()` siempre crea `TurnManager`
✅ **Correcto**: Detectar `phases` key para crear `PhaseManager`

❌ **Error 4**: No conectar `TimerService` al deserializar
✅ **Correcto**: Siempre llamar `setTimerService()` después de `fromArray()`

❌ **Error 5**: No restaurar timer en refresh
✅ **Correcto**: Fetch estado y simular `PhaseChangedEvent`

### 📚 Referencias

- **Trivia**: Ejemplo de juego single-fase
- **Mentiroso**: Ejemplo de juego multi-fase
- `RoundManager.php:65-153` - Factory y extracción de fases
- `BaseGameClient.js:185-236` - Handler de PhaseChangedEvent
- `game.blade.php:186-213` - Restauración de timer

---

## 🔄 Modo Interactivo (Backward Compatibility)

Si el usuario ejecuta `/create-game` sin argumentos, usar el flujo original de 12 preguntas interactivas (mantener compatibilidad).

---

## 🚀 Ejecución

**Al inicio:**
1. Detectar si hay archivo de descripción
2. Si hay archivo: Modo IA (análisis + preguntas sobre ambigüedades)
3. Si NO hay archivo: Modo Interactivo (12 preguntas)
4. Generar EN FASES con checkpoints
5. Validar después de cada fase
6. Mostrar progreso claro

**¡Comencemos!** 🚀
