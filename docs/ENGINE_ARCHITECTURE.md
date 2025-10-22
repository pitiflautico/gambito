# Arquitectura de Game Engines

**Versión:** 1.0
**Fecha:** 2025-10-22

## 🎯 Objetivo

Establecer una arquitectura desacoplada y mantenible para los motores de juegos (Engines), separando claramente:

1. **Lógica del juego** (específica de cada juego)
2. **Coordinación con módulos** (genérica, reutilizable)

## 📐 Principios de Diseño

### 1. Separación de Responsabilidades

```
┌─────────────────────────────────────────────────────────────┐
│                     GAME ENGINE                              │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌────────────────────────────────────────────────────┐    │
│  │  LÓGICA DEL JUEGO (Engine específico)             │    │
│  │  - ¿Qué pasa cuando un jugador responde?          │    │
│  │  - ¿Cómo se inicia una ronda?                     │    │
│  │  - ¿Cómo se calculan los puntos?                  │    │
│  │  - ¿Debe terminar el turno? (modo secuencial)    │    │
│  └────────────────────────────────────────────────────┘    │
│                         │                                    │
│                         ▼                                    │
│  ┌────────────────────────────────────────────────────┐    │
│  │  COORDINACIÓN (BaseGameEngine)                     │    │
│  │  - Detecta modo: simultáneo/secuencial            │    │
│  │  - ¿Cuándo termina la ronda? → RoundManager       │    │
│  │  - ¿Cuándo avanzar? → RoundManager                │    │
│  │  - ¿Cómo gestionar turnos? → TurnManager          │    │
│  │  - ¿Cómo calcular scores? → ScoreManager          │    │
│  └────────────────────────────────────────────────────┘    │
│                         │                                    │
└─────────────────────────┼────────────────────────────────────┘
                          │
                          ▼
          ┌───────────────────────────────────┐
          │         MÓDULOS                    │
          ├───────────────────────────────────┤
          │  - RoundManager                   │
          │  - TurnManager (simultaneous/sequential) │
          │  - ScoreManager                   │
          │  - TimerService                   │
          │  - SessionManager                 │
          └───────────────────────────────────┘
```

### 2. Modos de Juego Soportados

BaseGameEngine detecta automáticamente el modo del juego y adapta su coordinación:

#### Modo Simultáneo (Trivia, Quiz)
- **Característica**: Todos los jugadores actúan al mismo tiempo
- **Finalización**: Automática vía `RoundManager->shouldEndSimultaneousRound()`
- **Lógica**:
  - Primer jugador en acertar → termina inmediatamente
  - Todos respondieron → termina mostrando resultados

#### Modo Secuencial (Pictionary, UNO)
- **Característica**: Un jugador actúa por turno
- **Finalización**: El Engine decide retornando `should_end_turn: true`
- **Lógica**:
  - El juego decide cuándo terminar (ej. respuesta correcta, timeout)
  - BaseGameEngine programa el siguiente turno vía RoundManager

#### Modo Equipos (futura implementación)
- **Característica**: Grupos compiten
- **Finalización**: Similar a simultáneo pero agrupado por equipo

### 3. Desacoplamiento

❌ **NUNCA:**
- El Engine NO decide cuándo terminar rondas (lo hace RoundManager)
- El Engine NO programa delays manualmente (lo hace RoundManager)
- El Engine NO gestiona directamente turnos (lo hace TurnManager)
- El Engine NO duplica lógica de módulos

✅ **SIEMPRE:**
- El Engine SOLO define la lógica específica del juego
- El Engine PREGUNTA a los módulos (no decide por ellos)
- El Engine COORDINA entre lógica y módulos
- El Engine DELEGA responsabilidades a quien corresponde

## 🏗️ Estructura de un Engine

### Herencia

```php
BaseGameEngine (abstracto)
    ↓
TriviaEngine (concreto)
PictionaryEngine (concreto)
UnoEngine (concreto)
```

### Métodos Requeridos

Todo Engine debe implementar:

#### 1. `initialize(GameMatch $match): void`

Configurar el estado inicial del juego.

```php
public function initialize(GameMatch $match): void
{
    // Inicializar módulos
    $roundManager = new RoundManager(...);
    $scoreManager = new ScoreManager(...);

    // Configurar estado inicial del juego
    $match->game_state = [
        'phase' => 'waiting',
        'current_round' => 1,
        // ... estado específico del juego
    ];

    $match->save();
}
```

#### 2. `processRoundAction(GameMatch $match, Player $player, array $data): array`

Procesar la acción de un jugador **sin decidir si la ronda termina**.

```php
protected function processRoundAction(GameMatch $match, Player $player, array $data): array
{
    $gameState = $match->game_state;

    // Procesar la acción específica del juego
    $answerIndex = $data['answer'];
    $correctAnswer = $gameState['current_question']['correct'];
    $isCorrect = $answerIndex === $correctAnswer;

    // Guardar resultado
    $gameState['player_answers'][$player->id] = [
        'answer' => $answerIndex,
        'is_correct' => $isCorrect,
        'timestamp' => now()->timestamp,
    ];

    $match->game_state = $gameState;
    $match->save();

    // Broadcast evento
    event(new PlayerAnsweredEvent($match, $player, $isCorrect));

    // Retornar resultado (NO decide si terminar)
    return [
        'success' => true,
        'player_id' => $player->id,
        'is_correct' => $isCorrect,
        'message' => $isCorrect ? '¡Correcto!' : 'Incorrecto',
    ];
}
```

#### 3. `getAllPlayerResults(GameMatch $match): array`

Retornar todos los resultados de jugadores en formato estándar.

```php
protected function getAllPlayerResults(GameMatch $match): array
{
    $gameState = $match->game_state;
    $playerResults = [];

    foreach ($gameState['player_answers'] as $playerId => $answer) {
        $playerResults[$playerId] = [
            'success' => $answer['is_correct'],
            'data' => $answer,
        ];
    }

    return $playerResults;
}
```

#### 4. `startNewRound(GameMatch $match): void`

Iniciar una nueva ronda del juego.

```php
protected function startNewRound(GameMatch $match): void
{
    $gameState = $match->game_state;

    // Limpiar respuestas de ronda anterior
    $gameState['player_answers'] = [];

    // Obtener siguiente pregunta
    $nextIndex = $gameState['current_question_index'] + 1;
    $nextQuestion = $gameState['questions'][$nextIndex] ?? null;

    if (!$nextQuestion) {
        $this->finalize($match);
        return;
    }

    // Actualizar estado
    $gameState['current_question_index'] = $nextIndex;
    $gameState['current_question'] = $nextQuestion;
    $gameState['phase'] = 'question';

    $match->game_state = $gameState;
    $match->save();

    // Broadcast evento
    event(new QuestionStartedEvent($match, $nextQuestion));
}
```

#### 5. `endCurrentRound(GameMatch $match): void`

Finalizar la ronda actual y calcular resultados.

```php
protected function endCurrentRound(GameMatch $match): void
{
    $gameState = $match->game_state;

    // Calcular puntos con ScoreManager
    $scoreManager = ScoreManager::fromArray(
        playerIds: array_keys($gameState['scores']),
        data: $gameState,
        calculator: new TriviaScoreCalculator()
    );

    foreach ($gameState['player_answers'] as $playerId => $answer) {
        if ($answer['is_correct']) {
            $scoreManager->awardPoints($playerId, 'correct_answer', [
                'seconds_elapsed' => $answer['seconds_elapsed']
            ]);
        }
    }

    // Actualizar scores
    $gameState['scores'] = $scoreManager->getScores();
    $gameState['phase'] = 'results';

    $match->game_state = $gameState;
    $match->save();

    // Broadcast evento
    event(new QuestionEndedEvent($match, $results));
}
```

#### 6. `finalize(GameMatch $match): array`

Finalizar el juego y calcular ranking.

```php
public function finalize(GameMatch $match): array
{
    $gameState = $match->game_state;
    $gameState['phase'] = 'finished';

    // Obtener ranking final
    $scoreManager = ScoreManager::fromArray(
        playerIds: array_keys($gameState['scores']),
        data: $gameState
    );

    $ranking = $scoreManager->getRanking();

    // Marcar match como terminado
    $match->finished_at = now();
    $match->game_state = $gameState;
    $match->save();

    // Broadcast evento final
    event(new GameFinishedEvent($match, $ranking));

    return [
        'ranking' => $ranking,
        'statistics' => $this->calculateStatistics($match),
    ];
}
```

## 🔄 Flujo Completo

### 1. Iniciar Partida

```php
$engine = new TriviaEngine();
$engine->initialize($match);
```

### 2. Jugador Actúa - Modo Simultáneo (Trivia)

```php
$result = $engine->processAction($match, $player, 'answer', ['answer' => 2]);
```

**Internamente (BaseGameEngine):**

```
1. processAction() [BaseGameEngine]
   ↓
2. processRoundAction() [TriviaEngine - lógica específica]
   - Verifica respuesta
   - Guarda resultado
   - Emite evento
   ↓
3. Detecta modo: 'simultaneous'
   ↓
4. getAllPlayerResults() [TriviaEngine]
   - Retorna todos los resultados
   ↓
5. roundManager->shouldEndSimultaneousRound() [RoundManager]
   - DECIDE si terminar ronda
   ↓
6. Si debe terminar:
   - endCurrentRound() [TriviaEngine - lógica específica]
   - roundManager->scheduleNextRound() [RoundManager]
     ↓
     (después de 5 segundos)
     ↓
   - startNewRound() [TriviaEngine - lógica específica]
```

### 3. Jugador Actúa - Modo Secuencial (Pictionary)

```php
$result = $engine->processAction($match, $player, 'confirm_answer', ['is_correct' => true]);
```

**Internamente (BaseGameEngine):**

```
1. processAction() [BaseGameEngine]
   ↓
2. processRoundAction() [PictionaryEngine - lógica específica]
   - Verifica que sea el drawer
   - Otorga puntos
   - Emite evento
   - Retorna ['success' => true, 'should_end_turn' => true]
   ↓
3. Detecta modo: 'sequential'
   ↓
4. Lee 'should_end_turn' del resultado
   ↓
5. Si debe terminar:
   - endCurrentRound() [PictionaryEngine - lógica específica]
   - roundManager->scheduleNextRound() [RoundManager]
     ↓
     (después de delay configurable)
     ↓
   - startNewRound() [PictionaryEngine - siguiente turno]
```

## 📦 Beneficios

### 1. Desacoplamiento
- Los módulos no saben nada de lógica de juegos
- Los Engines no reimplementan lógica de módulos
- Cambios en módulos no afectan Engines

### 2. Reutilización
- BaseGameEngine se reutiliza en todos los juegos
- Lógica de coordinación escrita una vez
- Módulos compartidos entre juegos

### 3. Mantenibilidad
- Lógica clara y separada
- Fácil de testear
- Fácil de extender

### 4. Consistencia
- Todos los juegos siguen la misma estructura
- Tests de convención validan cumplimiento
- Documentación clara

## ✅ Checklist para Crear un Nuevo Juego

- [ ] Extender `BaseGameEngine`
- [ ] Implementar `initialize()`
- [ ] Implementar `processRoundAction()`
- [ ] Implementar `getAllPlayerResults()`
- [ ] Implementar `startNewRound()`
- [ ] Implementar `endCurrentRound()`
- [ ] Implementar `finalize()`
- [ ] NO llamar a `dispatch()` manualmente
- [ ] NO decidir cuándo terminar rondas
- [ ] Delegar a RoundManager
- [ ] Delegar a ScoreManager
- [ ] Pasar tests de convención

## 📚 Referencias

- `app/Contracts/BaseGameEngine.php` - Clase base
- `app/Contracts/GameEngineInterface.php` - Interface
- `app/Services/Modules/RoundSystem/RoundManager.php` - Gestión de rondas
- `docs/GAMES_CONVENTION.md` - Convenciones generales

---

**Última actualización:** 2025-10-22
