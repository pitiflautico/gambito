# Contratos de Game Rules

Especificación detallada de los métodos que deben implementar GameRules y RoundRules.

---

## 1. GameRules (Reglas del Juego Completo)

Clase abstracta: `app/Contracts/GameRules.php`

### Responsabilidad
Definir cómo se gana el juego completo y cuándo termina.

### Métodos Obligatorios

#### `isGameComplete(GameMatch $match): bool`
**Propósito**: Determinar si el juego ha terminado.

**Retorna**:
- `true` si el juego terminó
- `false` si aún hay que jugar más rondas

**Ejemplos**:
- Trivia: Retorna `true` cuando se respondieron todas las preguntas
- Pictionary: Retorna `true` cuando alguien llega a 5 puntos
- UNO: Retorna `true` cuando alguien se queda sin cartas

---

#### `getWinner(GameMatch $match): ?Player`
**Propósito**: Determinar quién ganó el juego.

**Retorna**:
- `Player` si hay un ganador
- `null` si no hay ganador (empate o juego no terminado)

**Ejemplos**:
- Trivia: El jugador con más puntos al final
- Pictionary: El primer jugador en llegar a 5 puntos
- UNO: El primer jugador en quedarse sin cartas

---

#### `getTotalRounds(GameMatch $match): int`
**Propósito**: Obtener el número total de rondas del juego.

**Retorna**:
- Número entero de rondas totales

**Ejemplos**:
- Trivia: 10 (número de preguntas)
- Pictionary: Ilimitado hasta que alguien llegue a 5 puntos (retorna -1 o PHP_INT_MAX)
- UNO: Ilimitado hasta que alguien gane

---

#### `shouldStartNewRound(GameMatch $match): bool`
**Propósito**: Determinar si debe iniciar una nueva ronda.

**Retorna**:
- `true` si debe iniciar nueva ronda
- `false` si no (porque el juego terminó o está esperando algo)

**Ejemplos**:
- Trivia: `true` si quedan preguntas y la ronda anterior terminó
- Pictionary: `true` si nadie ha ganado aún
- UNO: Siempre `true` mientras haya jugadores

---

#### `getFinalResults(GameMatch $match): array`
**Propósito**: Calcular los resultados finales del juego.

**Retorna**: Array con estructura:
```php
[
    'winner' => Player|null,
    'ranking' => [
        ['position' => 1, 'player_id' => 1, 'player_name' => 'Alice', 'score' => 850],
        ['position' => 2, 'player_id' => 2, 'player_name' => 'Bob', 'score' => 720],
    ],
    'statistics' => [
        'total_rounds' => 10,
        'duration_seconds' => 320,
        'average_score' => 785,
        // ... estadísticas específicas del juego
    ]
]
```

---

## 2. RoundRules (Reglas de Una Ronda)

Clase abstracta: `app/Contracts/RoundRules.php`

### Responsabilidad
Definir cómo se juega y termina UNA ronda individual.

### Métodos Obligatorios

#### `shouldEndRound(GameMatch $match, array $actionResult): bool`
**Propósito**: Determinar si la ronda debe terminar después de una acción.

**Parámetros**:
- `$match`: La partida actual
- `$actionResult`: Resultado de la última acción procesada
  ```php
  [
      'success' => true/false,
      'player_id' => int,
      'action' => string,
      'is_correct' => bool, // para respuestas
      // ... datos específicos
  ]
  ```

**Retorna**:
- `true` si la ronda debe terminar
- `false` si la ronda continúa

**Ejemplos**:
- Trivia: `true` cuando alguien responde correctamente
- Pictionary: `true` cuando alguien adivina la palabra
- UNO: `true` cuando el jugador juega una carta válida o roba

---

#### `isValidAction(GameMatch $match, Player $player, string $action, array $data): bool`
**Propósito**: Validar si un jugador puede realizar una acción en el estado actual.

**Parámetros**:
- `$match`: La partida actual
- `$player`: El jugador que intenta la acción
- `$action`: Nombre de la acción (ej: 'answer', 'draw', 'guess_word')
- `$data`: Datos adicionales de la acción

**Retorna**:
- `true` si la acción es válida
- `false` si la acción no es válida

**Ejemplos**:
- Trivia: `false` si el jugador ya respondió esta pregunta
- Pictionary: `false` si el jugador es el dibujante (no puede adivinar)
- UNO: `false` si la carta no es jugable

---

#### `calculateRoundPoints(GameMatch $match): array`
**Propósito**: Calcular los puntos que gana cada jugador en esta ronda.

**Retorna**: Array con estructura:
```php
[
    player_id => points_earned,
    1 => 150,  // Alice ganó 150 puntos
    2 => 0,    // Bob no ganó puntos
    3 => 75,   // Charlie ganó 75 puntos
]
```

**Ejemplos**:
- Trivia: +100 base + bonus por velocidad para respuesta correcta
- Pictionary: +1 para dibujante y adivinador
- UNO: 0 (no hay puntos por ronda, solo gana quien se queda sin cartas)

---

#### `getRoundWinner(GameMatch $match): ?Player`
**Propósito**: Determinar quién ganó esta ronda específica.

**Retorna**:
- `Player` si hay un ganador de la ronda
- `null` si no hay ganador claro (todos fallaron, empate, etc.)

**Ejemplos**:
- Trivia: El primero en responder correctamente
- Pictionary: El primero en adivinar
- UNO: null (no hay ganador por ronda)

---

#### `getRoundResults(GameMatch $match): array`
**Propósito**: Obtener los resultados completos de la ronda para mostrar en UI.

**Retorna**: Array con estructura:
```php
[
    'winner' => Player|null,
    'points' => [player_id => points],
    'details' => [
        // Información específica del juego para mostrar
        'correct_answer' => 'Madrid',
        'player_responses' => [
            1 => ['answer' => 0, 'correct' => true, 'time' => 3.5],
            2 => ['answer' => 1, 'correct' => false, 'time' => 5.2],
        ]
    ]
]
```

---

## 3. Integración con BaseGameEngine

El GameEngine coordina usando estas clases:

```php
class BaseGameEngine
{
    protected GameRules $gameRules;
    protected RoundRules $roundRules;

    public function processAction(GameMatch $match, Player $player, string $action, array $data): array
    {
        // 1. Validar con RoundRules
        if (!$this->roundRules->isValidAction($match, $player, $action, $data)) {
            return ['success' => false, 'error' => 'Acción inválida'];
        }

        // 2. Procesar acción (implementado por cada juego)
        $actionResult = $this->processRoundAction($match, $player, $data);

        // 3. Consultar a RoundRules si termina la ronda
        if ($this->roundRules->shouldEndRound($match, $actionResult)) {
            $this->endCurrentRound($match);

            // 4. Consultar a GameRules si termina el juego
            if ($this->gameRules->isGameComplete($match)) {
                $this->finalize($match);
            } else if ($this->gameRules->shouldStartNewRound($match)) {
                $this->startNewRound($match);
            }
        }

        return $actionResult;
    }

    protected function endCurrentRound(GameMatch $match): void
    {
        // 1. Calcular puntos con RoundRules
        $points = $this->roundRules->calculateRoundPoints($match);

        // 2. Aplicar puntos a ScoreManager
        $scoreManager = ScoreManager::fromArray($match->game_state);
        foreach ($points as $playerId => $earned) {
            $scoreManager->addScore($playerId, $earned);
        }
        $this->saveScoreManager($match, $scoreManager);

        // 3. Obtener resultados con RoundRules
        $results = $this->roundRules->getRoundResults($match);

        // 4. Emitir evento
        event(new RoundEndedEvent($match, $results));
    }

    protected function finalize(GameMatch $match): array
    {
        // 1. Obtener resultados finales con GameRules
        $finalResults = $this->gameRules->getFinalResults($match);

        // 2. Emitir evento
        event(new GameFinishedEvent($match, $finalResults));

        return $finalResults;
    }
}
```

---

## 4. Ejemplo Completo: Trivia

### TriviaGameRules

```php
class TriviaGameRules extends GameRules
{
    public function isGameComplete(GameMatch $match): bool
    {
        $roundManager = RoundManager::fromArray($match->game_state);
        return $roundManager->isGameComplete();
    }

    public function getWinner(GameMatch $match): ?Player
    {
        $scoreManager = ScoreManager::fromArray($match->game_state);
        $ranking = $scoreManager->getRanking();

        if (empty($ranking)) return null;

        return Player::find($ranking[0]['player_id']);
    }

    public function getTotalRounds(GameMatch $match): int
    {
        return count($match->game_state['_config']['questions'] ?? []);
    }

    public function shouldStartNewRound(GameMatch $match): bool
    {
        return !$this->isGameComplete($match);
    }

    public function getFinalResults(GameMatch $match): array
    {
        $scoreManager = ScoreManager::fromArray($match->game_state);
        $ranking = $scoreManager->getRanking();

        // Enriquecer con nombres de jugadores
        $enrichedRanking = array_map(function($entry) {
            $player = Player::find($entry['player_id']);
            return [
                'position' => $entry['position'],
                'player_id' => $entry['player_id'],
                'player_name' => $player->name,
                'score' => $entry['score'],
            ];
        }, $ranking);

        return [
            'winner' => $this->getWinner($match),
            'ranking' => $enrichedRanking,
            'statistics' => [
                'total_questions' => $this->getTotalRounds($match),
                'players_count' => count($ranking),
            ]
        ];
    }
}
```

### TriviaRoundRules

```php
class TriviaRoundRules extends RoundRules
{
    public function shouldEndRound(GameMatch $match, array $actionResult): bool
    {
        // La ronda termina cuando alguien responde correctamente
        return $actionResult['is_correct'] ?? false;
    }

    public function isValidAction(GameMatch $match, Player $player, string $action, array $data): bool
    {
        $gameState = $match->game_state;

        // Solo se permite responder en fase 'question'
        if ($gameState['phase'] !== 'question') {
            return false;
        }

        // No se puede responder dos veces
        if (isset($gameState['player_answers'][$player->id])) {
            return false;
        }

        return true;
    }

    public function calculateRoundPoints(GameMatch $match): array
    {
        $gameState = $match->game_state;
        $timeLimit = $gameState['time_per_question'];
        $points = [];

        foreach ($gameState['player_answers'] as $playerId => $answer) {
            if ($answer['is_correct']) {
                // +100 base + bonus por velocidad
                $basePoints = 100;
                $timeBonus = max(0, 50 - $answer['seconds_elapsed']);
                $points[$playerId] = $basePoints + $timeBonus;
            } else {
                $points[$playerId] = 0;
            }
        }

        return $points;
    }

    public function getRoundWinner(GameMatch $match): ?Player
    {
        // El primero en responder correctamente
        $answers = $match->game_state['player_answers'] ?? [];

        foreach ($answers as $playerId => $answer) {
            if ($answer['is_correct']) {
                return Player::find($playerId);
            }
        }

        return null;
    }

    public function getRoundResults(GameMatch $match): array
    {
        $gameState = $match->game_state;

        return [
            'winner' => $this->getRoundWinner($match),
            'points' => $this->calculateRoundPoints($match),
            'details' => [
                'correct_answer' => $gameState['current_question']['correct'],
                'player_answers' => $gameState['player_answers'] ?? [],
            ]
        ];
    }
}
```

---

## 5. Convenciones

### Naming
- GameRules: `{Game}GameRules` (ej: `TriviaGameRules`, `PictionaryGameRules`)
- RoundRules: `{Game}RoundRules` (ej: `TriviaRoundRules`, `PictionaryRoundRules`)

### Ubicación
- Abstracciones: `app/Contracts/GameRules.php` y `app/Contracts/RoundRules.php`
- Implementaciones: `games/{slug}/Rules/GameRules.php` y `games/{slug}/Rules/RoundRules.php`

### Namespace
- Abstracciones: `App\Contracts`
- Implementaciones: `Games\{GameName}\Rules`

### Ejemplo
```
app/Contracts/
  ├── GameRules.php          (abstract class)
  └── RoundRules.php         (abstract class)

games/trivia/Rules/
  ├── GameRules.php          (TriviaGameRules extends GameRules)
  └── RoundRules.php         (TriviaRoundRules extends RoundRules)
```
