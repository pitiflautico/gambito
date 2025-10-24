# Flujo y Convenciones de Game Engines

## Índice
1. [Flujo Completo del Ciclo de Vida](#flujo-completo-del-ciclo-de-vida)
2. [Métodos Obligatorios](#métodos-obligatorios)
3. [Convenciones de Configuración](#convenciones-de-configuración)
4. [Eventos del Sistema](#eventos-del-sistema)
5. [Ejemplo Completo: Trivia](#ejemplo-completo-trivia)

---

## Flujo Completo del Ciclo de Vida

### 1. Creación de la Partida
```
Usuario presiona "Iniciar Juego" en el Lobby
↓
RoomController::apiStart()
↓
GameMatch::start()
  ├─ $engine->initialize()     → Guarda configuración
  ├─ $engine->startGame()       → Resetea módulos y comienza
  └─ emit GameStartedEvent      → Notifica a frontends
```

### 2. Métodos del Engine

#### `initialize(GameMatch $match): void`
**Propósito**: Guardar la configuración del juego.
**Se llama**: UNA VEZ al crear el match.
**NO debe**: Resetear scores, iniciar rondas, o emitir eventos.

**Responsabilidades**:
- Validar jugadores mínimos
- Cargar `config.json` del juego
- Cargar assets (preguntas, palabras, etc.)
- **Guardar TODO en `game_state['_config']`**
- Llamar a `$this->initializeModules()` para crear módulos automáticamente

**Estructura de `_config`**:
```php
'_config' => [
    // Settings del juego
    'questions_per_game' => 10,
    'time_per_question' => 15,
    'difficulty' => 'mixed',

    // Assets seleccionados
    'questions' => [...],  // o 'words', 'challenges', etc.

    // Configuración de módulos
    'player_ids' => [1, 2, 3],
    'turn_mode' => 'simultaneous',
    'total_rounds' => 10,

    // Cualquier otra configuración necesaria para reiniciar
]
```

#### `startGame(GameMatch $match): void`
**Propósito**: Iniciar/Reiniciar el juego desde cero.
**Se llama**: Cada vez que se quiere empezar o reiniciar.
**Puede llamarse múltiples veces** (ej: botón "Reiniciar Partida").

**Responsabilidades**:
1. Llamar a `$this->resetModules($match)` para resetear automáticamente:
   - Lee `config.json` del juego para saber qué módulos están enabled
   - Resetea SOLO los módulos que el juego usa
   - Scores a 0
   - Ronda a 1
   - Turno al primer jugador
   - Timers eliminados
   - Acepta overrides opcionales: `$this->resetModules($match, ['round_system' => ['current_round' => 5]])`

2. Leer configuración desde `game_state['_config']`

3. Setear estado inicial específico del juego:
   ```php
   $match->game_state = array_merge($match->game_state, [
       'phase' => 'question',  // o 'drawing', 'voting', etc.
       'current_question_index' => 0,
       'current_question' => $firstQuestion,
       'player_answers' => [],
       // ...estado específico del juego
   ]);
   ```

4. Iniciar timers si es necesario

5. Emitir primer evento del juego:
   ```php
   event(new RoundStartedEvent(...));
   ```

---

## Métodos Obligatorios

Todos los engines DEBEN implementar (de `GameEngineInterface`):

```php
interface GameEngineInterface {
    // Configuración y ciclo de vida
    public function initialize(GameMatch $match): void;
    public function startGame(GameMatch $match): void;
    public function advancePhase(GameMatch $match): void;
    public function finalize(GameMatch $match): array;

    // Interacción del jugador
    public function processAction(GameMatch $match, Player $player, string $action, array $data): array;
    public function getGameStateForPlayer(GameMatch $match, Player $player): array;

    // Gestión de jugadores
    public function handlePlayerDisconnect(GameMatch $match, Player $player): void;
    public function handlePlayerReconnect(GameMatch $match, Player $player): void;

    // Condición de victoria
    public function checkWinCondition(GameMatch $match): ?Player;
}
```

---

## Convenciones de Configuración

### Estructura de `game_state`

```php
$match->game_state = [
    // ========================================
    // CONFIGURACIÓN (NO cambia durante el juego)
    // ========================================
    '_config' => [
        'questions_per_game' => 10,
        'time_per_question' => 15,
        'questions' => [...],
        'player_ids' => [1, 2, 3],
        // ... cualquier config necesaria para reiniciar
    ],

    // ========================================
    // ESTADO DEL JUEGO (cambia durante el juego)
    // ========================================
    'phase' => 'question',  // 'waiting', 'question', 'results', 'final_results'
    'current_question_index' => 2,
    'current_question' => [...],
    'player_answers' => [...],

    // ========================================
    // MÓDULOS (gestionados por BaseGameEngine)
    // ========================================
    'round_system' => [
        'total_rounds' => 10,
        'current_round' => 3,
        // ...
    ],
    'scoring_system' => [
        'scores' => [
            1 => 250,
            2 => 100,
            3 => 0
        ],
        // ...
    ],
    'turn_system' => [
        'mode' => 'simultaneous',
        'current_turn_index' => 0,
        // ...
    ],
    'timer_system' => [
        'timers' => [
            'question_timer' => [...],
        ]
    ],
];
```

### Guardar vs Leer Configuración

**REGLA DE ORO**: Todo lo que necesitas para reiniciar el juego debe estar en `_config`.

✅ **CORRECTO**:
```php
// En initialize():
$match->game_state['_config']['questions'] = $selectedQuestions;

// En startGame():
$questions = $match->game_state['_config']['questions'];
$firstQuestion = $questions[0];
```

❌ **INCORRECTO**:
```php
// En startGame():
$questions = $this->selectQuestionsAgain();  // ❌ NO! Usa las guardadas
```

---

## Eventos del Sistema

### Eventos Base (Todos los Juegos)

Definidos en `config/game-events.php`:

1. **GameStartedEvent** - `game.started`
   - Se emite desde `GameMatch::start()`
   - El frontend sincroniza estado inicial

2. **RoundStartedEvent** - `game.round.started`
   - Nueva ronda/pregunta/fase comienza
   - Todos los frontends se sincronizan

3. **RoundEndedEvent** - `game.round.ended`
   - Ronda/pregunta terminó
   - Incluye resultados y scores actualizados

4. **PlayerActionEvent** - `game.player.action`
   - Un jugador realizó una acción
   - Otros frontends pueden mostrar "X está jugando..."

5. **PhaseChangedEvent** - `game.phase.changed`
   - Cambio de fase del juego (ej: 'question' → 'results')

6. **TurnChangedEvent** - `game.turn.changed`
   - Cambio de turno en modo secuencial
   - Solo relevante para juegos por turnos

### Eventos Específicos del Juego

Definidos en `games/{slug}/capabilities.json`:

```json
{
  "event_config": {
    "channel": "room.{roomCode}",
    "events": {
      "QuestionStartedEvent": {
        "name": ".trivia.question.started",
        "handler": "handleQuestionStarted"
      },
      "GameFinishedEvent": {
        "name": ".trivia.game.finished",
        "handler": "handleGameFinished"
      }
    }
  }
}
```

---

## Ejemplo Completo: Trivia

### 1. `initialize()` - Guardar Configuración

```php
public function initialize(GameMatch $match): void
{
    // 1. Validar jugadores
    if ($match->players->count() < 1) {
        throw new \Exception('Se requiere al menos 1 jugador');
    }

    // 2. Cargar config.json
    $config = json_decode(file_get_contents(base_path('games/trivia/config.json')), true);
    $questionsPerGame = $config['settings']['questions_per_game']['default'];
    $timePerQuestion = $config['settings']['time_per_question']['default'];

    // 3. Cargar y seleccionar preguntas
    $allQuestions = json_decode(file_get_contents(base_path('games/trivia/assets/questions.json')), true);
    $selectedQuestions = $this->selectQuestions($allQuestions, $questionsPerGame, 'mixed', 'mixed');

    // 4. ⭐ GUARDAR CONFIGURACIÓN EN _config
    $playerIds = $match->players->pluck('id')->toArray();

    $match->game_state = [
        '_config' => [
            'questions_per_game' => $questionsPerGame,
            'time_per_question' => $timePerQuestion,
            'questions' => $selectedQuestions,
            'player_ids' => $playerIds,
        ],
        'phase' => 'waiting',
        'questions' => $selectedQuestions,
        'time_per_question' => $timePerQuestion,
    ];

    $match->save();

    // 5. ⭐ INICIALIZAR MÓDULOS AUTOMÁTICAMENTE
    // Lee config.json y crea SOLO los módulos que están enabled
    $this->initializeModules($match, [
        'round_system' => [
            'total_rounds' => count($selectedQuestions)
        ],
        'scoring_system' => [
            'calculator' => new TriviaScoreCalculator()  // Específico de Trivia
        ],
    ]);
}
```

### 2. `startGame()` - Resetear y Comenzar

```php
public function startGame(GameMatch $match): void
{
    // 1. ⭐ Resetear módulos automáticamente según config.json
    //    Lee qué módulos están enabled y los resetea
    $this->resetModules($match);

    // 2. ⭐ Leer configuración guardada
    $config = $match->game_state['_config'];
    $questions = $config['questions'];
    $timePerQuestion = $config['time_per_question'];

    // 3. Setear estado inicial específico de Trivia
    $firstQuestion = $questions[0];

    $match->game_state = array_merge($match->game_state, [
        'phase' => 'question',
        'current_question_index' => 0,
        'current_question' => $firstQuestion,
        'player_answers' => [],
        'question_start_time' => now()->timestamp,
    ]);

    $match->save();

    // 4. Iniciar timer
    $timerService = TimerService::fromArray($match->game_state);
    $timerService->startTimer('question_timer', $timePerQuestion);

    $match->game_state = array_merge($match->game_state, $timerService->toArray());
    $match->save();

    // 5. ⭐ Emitir primer evento
    event(new RoundStartedEvent(
        match: $match,
        currentRound: 1,
        totalRounds: count($questions),
        phase: 'question'
    ));
}
```

---

## Módulos del Sistema

Los juegos pueden usar los módulos de `BaseGameEngine`:

### RoundManager
- Gestiona rondas y ciclos de juego
- `$this->getRoundManager($match)`
- `$this->saveRoundManager($match, $roundManager)`

### ScoreManager
- Gestiona puntuaciones
- `$this->getScoreManager($match)`
- `$this->saveScoreManager($match, $scoreManager)`

### TurnManager (vía RoundManager)
- Gestiona turnos en modo secuencial
- `$roundManager->getTurnManager()`

### TimerService
- Gestiona temporizadores
- `TimerService::fromArray($match->game_state)`

### RoleManager (opcional)
- Gestiona roles específicos del juego
- `$this->getRoleManager($match)`

---

## Helpers: `initializeModules()` y `resetModules()`

### `initializeModules()` - Crear módulos

Disponible en `BaseGameEngine`. **Se llama desde `initialize()`** para crear los módulos por primera vez.

```php
protected function initializeModules(GameMatch $match, array $moduleOverrides = []): void
{
    // 1. Lee config.json del juego para ver qué módulos están enabled
    // 2. Crea instancias de SOLO los módulos enabled:
    //    - TurnManager (lee mode desde config.json)
    //    - RoundManager (lee total_rounds desde config.json)
    //    - ScoreManager (necesita calculator en overrides)
    //    - TimerService
    // 3. Los guarda en game_state
}
```

**Uso en `initialize()`**:
```php
public function initialize(GameMatch $match): void
{
    // ... guardar configuración en _config ...

    // Crear módulos automáticamente
    $this->initializeModules($match, [
        'round_system' => [
            'total_rounds' => count($selectedQuestions)  // Override desde el juego
        ],
        'scoring_system' => [
            'calculator' => new TriviaScoreCalculator()  // REQUERIDO para cada juego
        ],
    ]);
}
```

**Módulos Opcionales vs Requeridos**:
- `turn_system`: Modo se lee desde config.json
- `round_system`: Total rounds puede venir de config.json o override
- `scoring_system`: **REQUIERE** `calculator` en overrides (específico del juego)
- `timer_system`: No requiere configuración
- `teams_system`: Se crea en el lobby, solo se detecta aquí

---

### `resetModules()` - Resetear módulos

Disponible en `BaseGameEngine`. **Se llama desde `startGame()`** para resetear módulos existentes.

```php
protected function resetModules(GameMatch $match, array $overrides = []): void
{
    // 1. Lee config.json del juego para ver qué módulos están enabled
    // 2. Resetea SOLO los módulos que el juego usa:
    //    - RoundManager: current_round = 1
    //    - ScoreManager: scores = 0
    //    - TurnManager: current_turn_index = 0
    //    - TimerService: timers eliminados
    //    - TeamsSystem: detectado (se resetea en lógica del juego)
    //    - RolesSystem: detectado (se resetea en lógica del juego)
}
```

**Uso Básico**:
```php
public function startGame(GameMatch $match): void
{
    // Resetear todos los módulos enabled del juego
    $this->resetModules($match);

    // Tu lógica específica...
}
```

**Uso con Overrides**:
```php
public function startGame(GameMatch $match): void
{
    // Empezar desde la ronda 5 en lugar de la ronda 1
    $this->resetModules($match, [
        'round_system' => ['current_round' => 5]
    ]);

    // O dar puntos iniciales a algunos jugadores
    $this->resetModules($match, [
        'scoring_system' => [
            'scores' => [
                123 => 100,  // Player ID 123 empieza con 100 puntos
                456 => 50,   // Player ID 456 empieza con 50 puntos
            ]
        ]
    ]);
}
```

---

## Checklist para Implementar un Nuevo Juego

- [ ] Crear `games/{slug}/config.json` con settings
- [ ] Crear `games/{slug}/{SlugEngine}.php` que extienda `BaseGameEngine`
- [ ] Implementar `initialize()` que:
  - [ ] Valide jugadores mínimos
  - [ ] Cargue configuración
  - [ ] Cargue assets (preguntas, palabras, etc.)
  - [ ] **Guarde TODO en `_config`**
  - [ ] Llame a `$this->initializeModules()` con overrides necesarios (ej: calculator)
- [ ] Implementar `startGame()` que:
  - [ ] Llame a `$this->resetModules($match)` (resetea automáticamente según config.json)
  - [ ] Lea `_config`
  - [ ] Setee estado inicial específico
  - [ ] Inicie timers si necesario
  - [ ] Emita primer evento (`RoundStartedEvent`)
- [ ] Implementar métodos abstractos de `BaseGameEngine`:
  - [ ] `processRoundAction()`
  - [ ] `startNewRound()`
  - [ ] `endCurrentRound()`
  - [ ] `getAllPlayerResults()`
- [ ] Definir eventos específicos en `capabilities.json`
- [ ] Implementar frontend en `resources/js/{slug}-game.js` que extienda `BaseGameClient`

---

## Resumen

### Reglas del Sistema

**REGLA 1**: `initialize()` SOLO guarda configuración en `_config` y llama a `initializeModules()`
**REGLA 2**: `initializeModules()` lee `config.json` y crea SOLO los módulos enabled
**REGLA 3**: `startGame()` SIEMPRE llama a `$this->resetModules($match)` primero
**REGLA 4**: `resetModules()` lee `config.json` y resetea SOLO los módulos enabled
**REGLA 5**: `startGame()` SIEMPRE lee desde `_config`, nunca recalcula
**REGLA 6**: `GameMatch::start()` llama a ambos: `initialize()` + `startGame()`
**REGLA 7**: Los frontends se sincronizan con `GameStartedEvent`

### Ventajas del Sistema

Con este flujo, cualquier juego puede:
- ✅ **Crear módulos automáticamente** según `config.json`
- ✅ **Reiniciarse desde cero** con un botón "Jugar de nuevo"
- ✅ **Sin código repetitivo** - Solo 2 llamadas: `initializeModules()` + `resetModules()`
- ✅ **Mantener consistencia** entre múltiples inicios
- ✅ **Usar módulos comunes** de forma estandarizada
- ✅ **Overrides flexibles** para casos especiales

### Flujo Completo

```
Usuario presiona "Iniciar Juego"
↓
GameMatch::start()
├─ 1. $engine->initialize()
│   ├─ Guardar config en _config
│   └─ $this->initializeModules()  → Crear módulos según config.json
├─ 2. $engine->startGame()
│   ├─ $this->resetModules()       → Resetear módulos a 0
│   ├─ Leer _config
│   ├─ Setear estado inicial
│   └─ Emitir RoundStartedEvent
└─ 3. emit GameStartedEvent        → Notificar frontends
```
