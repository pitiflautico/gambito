# Convenciones: Reglas del Juego y Rondas

Este documento establece las convenciones para implementar las reglas de juego y el sistema de rondas en la plataforma Gambito.

## Tabla de Contenidos

1. [Arquitectura General](#arquitectura-general)
2. [Ciclo de Vida de una Ronda](#ciclo-de-vida-de-una-ronda)
3. [Contratos de Reglas del Juego](#contratos-de-reglas-del-juego)
4. [Eventos: Genéricos vs Específicos](#eventos-genéricos-vs-específicos)
5. [Estrategias de Finalización](#estrategias-de-finalización)
6. [Ejemplo Completo: Trivia](#ejemplo-completo-trivia)

---

## Arquitectura General

### Separación de Responsabilidades

```
┌─────────────────────────────────────────────────────────┐
│                    GAME ENGINE                          │
│  (Define QUÉ pasa en cada fase del juego)              │
│                                                         │
│  - processRoundAction()   → Validar y procesar acción  │
│  - startNewRound()        → Iniciar nueva ronda        │
│  - endCurrentRound()      → Calcular resultados        │
│  - getAllPlayerResults()  → Obtener estado actual      │
└─────────────────────────────────────────────────────────┘
                            │
                            ↓
┌─────────────────────────────────────────────────────────┐
│                  MÓDULOS DEL SISTEMA                    │
│  (Gestionan CUÁNDO y CÓMO suceden las cosas)          │
│                                                         │
│  - RoundManager     → Cuándo avanzar ronda             │
│  - TurnManager      → En qué orden juegan              │
│  - ScoreManager     → Cómo se calculan puntos          │
│  - TimerService     → Límites de tiempo                │
│  - EndRoundStrategy → Cuándo termina la ronda          │
└─────────────────────────────────────────────────────────┘
```

**IMPORTANTE:**
- El **Engine** define la lógica del juego (las reglas)
- Los **Módulos** gestionan el flujo (cuándo avanzar, quién juega, etc.)
- Esta separación permite reutilizar módulos entre juegos diferentes

---

## Ciclo de Vida de una Ronda

### Fases Obligatorias

Cada ronda DEBE pasar por estas fases en orden:

```
1. START_ROUND
   ├─ startNewRound() se ejecuta
   ├─ Emite: RoundStartedEvent (genérico)
   ├─ Emite: [EventoEspecífico]StartedEvent (ej: QuestionStartedEvent)
   └─ Estado: round_in_progress = true

2. PLAYER_ACTIONS
   ├─ processRoundAction() se ejecuta por cada jugador
   ├─ Emite: PlayerActionEvent (genérico)
   ├─ Emite: [EventoEspecífico]Event (ej: PlayerAnsweredEvent)
   └─ Valida según estrategia: ¿Debe terminar la ronda?

3. CHECK_END_CONDITION
   ├─ EndRoundStrategy.shouldEnd() verifica condición
   │  ├─ Sequential: Todos los jugadores jugaron su turno
   │  ├─ Simultaneous: Alguien ganó O todos respondieron
   │  └─ Free: Condición custom del juego
   └─ Si debe terminar → Fase 4

4. END_ROUND
   ├─ endCurrentRound() se ejecuta
   ├─ Calcula resultados y actualiza scores
   ├─ Emite: RoundEndedEvent (genérico) con timing
   ├─ Emite: [EventoEspecífico]EndedEvent (ej: QuestionEndedEvent)
   └─ Estado: round_in_progress = false

5. TRANSITION
   ├─ TimingModule procesa countdown (ej: 5s)
   ├─ Muestra resultados en frontend
   ├─ Callback: notifyReadyForNextRound()
   └─ Si hay más rondas → Vuelve a Fase 1
      Si no → GameFinishedEvent
```

### Convención: Método `shouldEndRound()`

Cada Engine puede implementar esta lógica delegando a la estrategia:

```php
protected function shouldEndRound(GameMatch $match, array $playerResults): bool
{
    $strategy = $this->getEndRoundStrategy($match);
    $decision = $strategy->shouldEnd($match, $playerResults);

    return $decision['should_end'];
}
```

**Estrategias disponibles:**
- `SequentialEndStrategy`: Para turnos secuenciales (ej: Pictionary)
- `SimultaneousEndStrategy`: Para juegos simultáneos (ej: Trivia)
- `FreeEndStrategy`: Para juegos sin orden (ej: Party games)

---

## Contratos de Reglas del Juego

### Métodos Abstractos Obligatorios

Cada Engine DEBE implementar estos métodos:

#### 1. `processRoundAction(GameMatch $match, Player $player, array $data): array`

**Responsabilidad:** Validar y procesar la acción de un jugador.

**DEBE retornar:**
```php
return [
    'success' => bool,      // ¿La acción fue exitosa?
    'player_id' => int,     // ID del jugador
    'data' => mixed,        // Datos adicionales (ej: respuesta correcta)
    'points' => int,        // Puntos ganados (opcional)
    'timestamp' => float,   // Tiempo de respuesta (opcional)
];
```

**NO DEBE:**
- Decidir si la ronda termina (lo hace la Strategy)
- Avanzar la ronda manualmente
- Emitir eventos genéricos (los emite BaseGameEngine)

**SÍ DEBE:**
- Validar que la acción es válida según las reglas
- Guardar el resultado en `game_state`
- Calcular puntos si aplica
- Emitir eventos específicos del juego

**Ejemplo (Trivia):**
```php
protected function processRoundAction(GameMatch $match, Player $player, array $data): array
{
    $gameState = $match->game_state;
    $currentQuestion = $gameState['current_question'];
    $selectedAnswer = $data['answer_id'] ?? null;

    // Validar respuesta
    $isCorrect = ($selectedAnswer === $currentQuestion['correct_answer']);

    // Calcular puntos con bonus de velocidad
    $points = 0;
    if ($isCorrect) {
        $timeElapsed = $data['time_elapsed'] ?? 0;
        $scoreManager = $this->getScoreManager($match, new TriviaScoreCalculator());
        $points = $scoreManager->addScore($player->id, 'correct_answer', [
            'time_limit' => $gameState['time_per_question'],
            'time_elapsed' => $timeElapsed
        ]);
    }

    // Guardar resultado en game_state
    $gameState['round_results'][$player->id] = [
        'answer_id' => $selectedAnswer,
        'is_correct' => $isCorrect,
        'points' => $points,
        'time_elapsed' => $timeElapsed
    ];
    $match->game_state = $gameState;
    $match->save();

    // Emitir evento específico
    event(new PlayerAnsweredEvent(
        match: $match,
        player: $player,
        isCorrect: $isCorrect,
        points: $points
    ));

    return [
        'success' => $isCorrect,
        'player_id' => $player->id,
        'data' => ['is_correct' => $isCorrect],
        'points' => $points,
        'timestamp' => $timeElapsed
    ];
}
```

#### 2. `startNewRound(GameMatch $match): void`

**Responsabilidad:** Inicializar el estado de una nueva ronda.

**DEBE:**
- Preparar el estado para la nueva ronda en `game_state`
- Limpiar resultados de la ronda anterior
- Emitir evento específico del juego
- Iniciar timer si aplica

**NO DEBE:**
- Emitir RoundStartedEvent (lo hace BaseGameEngine)
- Avanzar el contador de ronda (lo hace RoundManager)
- Gestionar turnos (lo hace TurnManager)

**Ejemplo (Trivia):**
```php
protected function startNewRound(GameMatch $match): void
{
    $gameState = $match->game_state;
    $roundManager = $this->getRoundManager($match);
    $currentRound = $roundManager->getCurrentRound();

    // Obtener pregunta para esta ronda
    $question = $gameState['questions'][$currentRound - 1] ?? null;

    if (!$question) {
        throw new \RuntimeException("No question for round {$currentRound}");
    }

    // Preparar estado de la ronda
    $gameState['current_question'] = $question;
    $gameState['round_results'] = [];
    $gameState['round_start_time'] = now()->timestamp;

    $match->game_state = $gameState;
    $match->save();

    // Emitir evento específico
    event(new QuestionStartedEvent(
        match: $match,
        question: $question,
        roundNumber: $currentRound,
        totalRounds: $roundManager->getTotalRounds(),
        timeLimit: $gameState['time_per_question']
    ));

    // Iniciar timer
    $timerService = new TimerService();
    $timerService->startTimer(
        match: $match,
        duration: $gameState['time_per_question'],
        onExpire: fn() => $this->handleTimeExpired($match)
    );
}
```

#### 3. `endCurrentRound(GameMatch $match): void`

**Responsabilidad:** Calcular resultados y actualizar estado.

**DEBE:**
- Calcular resultados finales de la ronda
- Actualizar scores
- Emitir evento específico con resultados
- Limpiar estado temporal

**NO DEBE:**
- Emitir RoundEndedEvent (lo hace BaseGameEngine)
- Decidir si hay siguiente ronda (lo hace RoundManager)
- Avanzar el contador de ronda (lo hace RoundManager)

**Ejemplo (Trivia):**
```php
protected function endCurrentRound(GameMatch $match): void
{
    $gameState = $match->game_state;
    $roundResults = $gameState['round_results'] ?? [];
    $currentQuestion = $gameState['current_question'];

    // Determinar si hubo ganador
    $winner = null;
    foreach ($roundResults as $playerId => $result) {
        if ($result['is_correct']) {
            $winner = $playerId;
            break;
        }
    }

    // Obtener scores actualizados
    $scoreManager = $this->getScoreManager($match, new TriviaScoreCalculator());
    $scores = $scoreManager->getScores();

    // Preparar resultados detallados
    $questionResults = [
        'question' => $currentQuestion,
        'correct_answer' => $currentQuestion['correct_answer'],
        'player_results' => $roundResults,
        'winner_id' => $winner
    ];

    // Guardar en historial
    if (!isset($gameState['round_history'])) {
        $gameState['round_history'] = [];
    }
    $gameState['round_history'][] = $questionResults;

    $match->game_state = $gameState;
    $match->save();

    // Emitir evento específico
    event(new QuestionEndedEvent(
        match: $match,
        results: $questionResults,
        scores: $scores
    ));
}
```

#### 4. `getAllPlayerResults(GameMatch $match): array`

**Responsabilidad:** Retornar el estado actual de todos los jugadores.

**DEBE retornar:**
```php
return [
    player_id => [
        'success' => bool,    // ¿Completó su acción exitosamente?
        'data' => mixed,      // Datos adicionales
        'timestamp' => float  // Cuándo actuó (opcional)
    ],
    ...
];
```

**Se usa para:**
- Que la Strategy determine si debe terminar la ronda
- Mostrar estado en tiempo real a los jugadores

**Ejemplo (Trivia):**
```php
protected function getAllPlayerResults(GameMatch $match): array
{
    $gameState = $match->game_state;
    return $gameState['round_results'] ?? [];
}
```

---

## Eventos: Genéricos vs Específicos

### Eventos Genéricos (en todos los juegos)

Estos eventos son emitidos automáticamente por `BaseGameEngine`:

| Evento | Cuándo | Datos |
|--------|--------|-------|
| `GameStartedEvent` | Al iniciar el juego | `game_state`, `timing` |
| `RoundStartedEvent` | Al iniciar cada ronda | `current_round`, `total_rounds` |
| `RoundEndedEvent` | Al terminar cada ronda | `scores`, `results`, `timing` |
| `PlayerActionEvent` | Cada acción de jugador | `player`, `action_type` |
| `GameFinishedEvent` | Al terminar el juego | `winner`, `final_scores` |

**Convención:** Los eventos genéricos van en `app/Events/Game/`

### Eventos Específicos (por juego)

Cada juego define sus propios eventos para lógica específica:

**Trivia:**
- `QuestionStartedEvent`: Al mostrar una pregunta
- `PlayerAnsweredEvent`: Cuando un jugador responde
- `QuestionEndedEvent`: Al finalizar una pregunta

**Pictionary:**
- `DrawingStartedEvent`: Al iniciar turno de dibujo
- `CanvasDrawEvent`: Cada trazo del dibujante
- `PlayerGuessedEvent`: Cuando alguien adivina

**Convención:** Los eventos específicos van en `games/{slug}/Events/`

### Regla: ¿Cuándo emitir qué evento?

```
┌─────────────────────────────────────────────────────┐
│  GENÉRICO: Información común a todos los juegos     │
│  ├─ Estado de rondas (actual, total)                │
│  ├─ Scores de jugadores                             │
│  ├─ Timing metadata (delays, countdowns)            │
│  └─ Estado del juego (iniciado, terminado)          │
└─────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────┐
│  ESPECÍFICO: Información única del juego            │
│  ├─ Pregunta y respuestas (Trivia)                  │
│  ├─ Canvas y trazos (Pictionary)                    │
│  ├─ Cartas y mazo (UNO)                             │
│  └─ Cualquier dato que solo ese juego necesita      │
└─────────────────────────────────────────────────────┘
```

---

## Estrategias de Finalización

### Cuándo usar cada Strategy

| Strategy | Cuándo usar | Ejemplo |
|----------|-------------|---------|
| **SequentialEndStrategy** | Turnos secuenciales, uno a la vez | Pictionary (dibuja uno, adivinan otros) |
| **SimultaneousEndStrategy** | Todos juegan al mismo tiempo | Trivia (todos responden la pregunta) |
| **FreeEndStrategy** | Sin orden específico | Party games, minijuegos |

### Simultaneous: Reglas de Finalización

En modo simultáneo (como Trivia), la ronda termina cuando:

1. **Alguien tiene éxito** → Termina inmediatamente
2. **Todos fallaron** → Termina cuando todos respondieron
3. **Algunos fallaron, otros no respondieron** → Continúa esperando

```php
// En SimultaneousEndStrategy
public function shouldEnd(GameMatch $match, array $playerResults): array
{
    $activePlayers = $this->getActivePlayers($match);
    $totalActive = count($activePlayers);
    $responded = count($playerResults);

    // ¿Alguien ganó?
    foreach ($playerResults as $result) {
        if ($result['success'] ?? false) {
            return [
                'should_end' => true,
                'reason' => 'player_succeeded',
                'winner_found' => true
            ];
        }
    }

    // ¿Todos respondieron?
    if ($responded >= $totalActive) {
        return [
            'should_end' => true,
            'reason' => 'all_failed',
            'winner_found' => false
        ];
    }

    // Continuar esperando
    return [
        'should_end' => false,
        'reason' => 'waiting_for_players'
    ];
}
```

---

## Ejemplo Completo: Trivia

### 1. Configuración (config.json)

```json
{
  "id": "trivia",
  "modules": {
    "turn_system": {
      "enabled": true,
      "mode": "simultaneous"
    },
    "round_system": {
      "enabled": true,
      "total_rounds": 10
    },
    "scoring_system": {
      "enabled": true
    },
    "timer_system": {
      "enabled": true
    }
  },
  "timing": {
    "game_start": {
      "auto_next": true,
      "delay": 3,
      "message": "Empezando"
    },
    "round_ended": {
      "auto_next": true,
      "delay": 5,
      "message": "Siguiente pregunta"
    }
  }
}
```

### 2. Engine (TriviaEngine.php)

```php
class TriviaEngine extends BaseGameEngine
{
    // Estrategia: Simultaneous (todos responden al mismo tiempo)
    protected function getEndRoundStrategy(GameMatch $match): EndRoundStrategy
    {
        return new SimultaneousEndStrategy();
    }

    // Regla: Validar respuesta y calcular puntos con bonus
    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        // Implementación arriba...
    }

    // Regla: Mostrar nueva pregunta
    protected function startNewRound(GameMatch $match): void
    {
        // Implementación arriba...
    }

    // Regla: Calcular resultados de la pregunta
    protected function endCurrentRound(GameMatch $match): void
    {
        // Implementación arriba...
    }

    // Estado: Quién ya respondió
    protected function getAllPlayerResults(GameMatch $match): array
    {
        return $match->game_state['round_results'] ?? [];
    }
}
```

### 3. Flujo Completo

```
1. GameMatch->start()
   └─> Engine->startGame()
       ├─ resetModules() (volver a ronda 1, scores a 0)
       └─ Emite GameStartedEvent con timing

2. [Frontend countdown: 3s]

3. Engine->startNewRound()
   ├─ Carga pregunta actual
   ├─ Emite QuestionStartedEvent
   └─ Inicia timer de 15s

4. [Jugadores responden]
   └─ Controller->answer()
       └─> Engine->handlePlayerAction()
           ├─ processRoundAction() → Valida respuesta
           ├─ Emite PlayerAnsweredEvent
           ├─ Strategy->shouldEnd() → ¿Terminar?
           │   ├─ SI alguien acertó → endCurrentRound()
           │   └─ SI todos respondieron → endCurrentRound()
           └─ NO → Esperar más respuestas

5. Engine->endCurrentRound()
   ├─ Calcula resultados finales
   ├─ Actualiza scores
   ├─ Emite QuestionEndedEvent
   └─ Emite RoundEndedEvent con timing

6. [Frontend countdown: 5s]

7. ¿Quedan rondas?
   ├─ SÍ → Volver a paso 3
   └─ NO → Emitir GameFinishedEvent
```

---

## Checklist: Crear un Nuevo Juego

Al implementar un nuevo juego, asegúrate de:

### Configuración
- [ ] Crear `games/{slug}/config.json` con módulos y timing
- [ ] Definir `customizableSettings` (opciones del host)
- [ ] Configurar `turnSystemConfig` (modo de turnos)

### Engine
- [ ] Extender `BaseGameEngine`
- [ ] Implementar `processRoundAction()` (reglas de validación)
- [ ] Implementar `startNewRound()` (iniciar ronda)
- [ ] Implementar `endCurrentRound()` (calcular resultados)
- [ ] Implementar `getAllPlayerResults()` (estado actual)
- [ ] Implementar `getEndRoundStrategy()` (cuándo termina)

### Eventos
- [ ] Crear eventos específicos en `games/{slug}/Events/`
- [ ] Emitir eventos genéricos (automático via BaseGameEngine)
- [ ] Emitir eventos específicos en los métodos correspondientes

### Frontend
- [ ] Extender `BaseGameClient`
- [ ] Implementar handlers para eventos específicos
- [ ] Implementar `onGameReady()` callback
- [ ] Implementar `getCountdownElement()` para timing

### Puntuación (si aplica)
- [ ] Crear `ScoreCalculator` específico en `games/{slug}/`
- [ ] Implementar `calculate()` para eventos del juego
- [ ] Usar `ScoreManager` via helper de BaseGameEngine

---

## Resumen de Convenciones

| Convención | Regla |
|------------|-------|
| **Módulos tienen reset()** | Todos los módulos deben tener método `reset()` |
| **Timing en config.json** | Cada juego define sus delays y mensajes |
| **Eventos genéricos automáticos** | BaseGameEngine los emite, no el Engine específico |
| **Eventos específicos manuales** | El Engine específico los emite en sus métodos |
| **Strategy decide cuándo terminar** | El Engine NO decide, delega a la Strategy |
| **Engine define QUÉ, Módulos CUÁNDO** | Separación clara de responsabilidades |
| **game_state es la fuente de verdad** | Todo el estado se guarda aquí |
| **Helpers para acceder a módulos** | Usar `getRoundManager()`, `getScoreManager()`, etc. |

