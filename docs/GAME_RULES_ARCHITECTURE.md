# Arquitectura de Reglas de Juego

## Problema

Cada juego tiene dos tipos de lógica que necesitan estar claramente separadas:

1. **Lógica de Ronda**: Cómo se juega UNA ronda individual
2. **Lógica de Juego**: Cómo el conjunto de rondas determina el ganador

Actualmente esta lógica está mezclada en el GameEngine, lo que hace difícil:
- Entender qué determina el fin de una ronda vs el fin del juego
- Reutilizar lógica común entre juegos
- Modificar reglas sin romper otras partes

## Solución: Separar en 2 Clases

```
┌─────────────────────────────────────────────┐
│           GameEngine (Trivia)               │
│  - Coordina el flujo                        │
│  - Usa GameRules y RoundRules               │
└─────────────────────────────────────────────┘
           │                    │
           ▼                    ▼
┌──────────────────┐   ┌──────────────────┐
│   GameRules      │   │   RoundRules     │
│  (juego entero)  │   │  (una ronda)     │
└──────────────────┘   └──────────────────┘
```

### GameRules (Reglas del Juego Completo)

**Responsabilidad**: Definir cómo se gana el juego completo.

**Métodos**:
- `isGameComplete(match)`: ¿El juego terminó?
- `getWinner(match)`: ¿Quién ganó el juego?
- `getTotalRounds()`: Número total de rondas
- `shouldStartNewRound(match)`: ¿Debe iniciar nueva ronda?

**Ejemplo Trivia**:
```php
class TriviaGameRules extends GameRules
{
    public function isGameComplete(GameMatch $match): bool
    {
        // El juego termina cuando se respondieron todas las preguntas
        $roundManager = RoundManager::fromArray($match->game_state);
        return $roundManager->isGameComplete();
    }

    public function getWinner(GameMatch $match): ?Player
    {
        // Gana quien tenga más puntos al final
        $scores = $this->getScores($match->game_state);
        arsort($scores);
        $winnerId = array_key_first($scores);
        return Player::find($winnerId);
    }

    public function getTotalRounds(): int
    {
        return $this->config['questions_per_game'];
    }
}
```

**Ejemplo Pictionary**:
```php
class PictionaryGameRules extends GameRules
{
    public function isGameComplete(GameMatch $match): bool
    {
        // El juego termina cuando alguien llega a 5 puntos
        $scores = $this->getScores($match->game_state);
        return max($scores) >= 5;
    }

    public function getWinner(GameMatch $match): ?Player
    {
        // Gana el primero en llegar a 5 puntos
        $scores = $this->getScores($match->game_state);
        foreach ($scores as $playerId => $score) {
            if ($score >= 5) {
                return Player::find($playerId);
            }
        }
        return null;
    }
}
```

---

### RoundRules (Reglas de Una Ronda)

**Responsabilidad**: Definir cómo se juega y termina UNA ronda.

**Métodos**:
- `shouldEndRound(match, actionResult)`: ¿La ronda debe terminar?
- `getRoundWinner(match)`: ¿Quién ganó esta ronda?
- `calculateRoundPoints(match)`: Calcular puntos de la ronda
- `isValidAction(match, player, action)`: ¿La acción es válida?

**Ejemplo Trivia**:
```php
class TriviaRoundRules extends RoundRules
{
    public function shouldEndRound(GameMatch $match, array $actionResult): bool
    {
        // La ronda termina cuando ALGUIEN responde correctamente
        return $actionResult['is_correct'] ?? false;
    }

    public function getRoundWinner(GameMatch $match): ?Player
    {
        // En Trivia, el primero en acertar gana la ronda
        $answers = $match->game_state['player_answers'] ?? [];
        foreach ($answers as $playerId => $answer) {
            if ($answer['is_correct']) {
                return Player::find($playerId);
            }
        }
        return null;
    }

    public function calculateRoundPoints(GameMatch $match): array
    {
        // +100 puntos base, +bonus por velocidad
        $results = [];
        foreach ($match->game_state['player_answers'] as $playerId => $answer) {
            if ($answer['is_correct']) {
                $basePoints = 100;
                $timeBonus = max(0, 50 - $answer['seconds_elapsed']);
                $results[$playerId] = $basePoints + $timeBonus;
            } else {
                $results[$playerId] = 0;
            }
        }
        return $results;
    }
}
```

**Ejemplo Pictionary**:
```php
class PictionaryRoundRules extends RoundRules
{
    public function shouldEndRound(GameMatch $match, array $actionResult): bool
    {
        // La ronda termina cuando ALGUIEN adivina la palabra
        return $actionResult['success'] && $actionResult['action'] === 'guess_word';
    }

    public function getRoundWinner(GameMatch $match): ?Player
    {
        // El primero en adivinar gana la ronda
        $guesses = $match->game_state['player_guesses'] ?? [];
        foreach ($guesses as $playerId => $guess) {
            if ($guess['is_correct']) {
                return Player::find($playerId);
            }
        }
        return null;
    }

    public function calculateRoundPoints(GameMatch $match): array
    {
        // El dibujante y el adivinador ganan 1 punto
        $winner = $this->getRoundWinner($match);
        $drawer = $this->getCurrentDrawer($match);

        return [
            $winner->id => 1,
            $drawer->id => 1
        ];
    }
}
```

---

## Integración con GameEngine

El GameEngine usa estas clases para coordinar:

```php
class TriviaEngine extends BaseGameEngine
{
    protected GameRules $gameRules;
    protected RoundRules $roundRules;

    public function __construct()
    {
        $this->gameRules = new TriviaGameRules();
        $this->roundRules = new TriviaRoundRules();
    }

    public function processAction(GameMatch $match, Player $player, string $action, array $data): array
    {
        // 1. Validar acción con RoundRules
        if (!$this->roundRules->isValidAction($match, $player, $action)) {
            return ['success' => false, 'error' => 'Acción inválida'];
        }

        // 2. Procesar acción
        $actionResult = $this->processRoundAction($match, $player, $data);

        // 3. Consultar a RoundRules si debe terminar la ronda
        if ($this->roundRules->shouldEndRound($match, $actionResult)) {
            $this->endCurrentRound($match);

            // 4. Consultar a GameRules si debe terminar el juego
            if ($this->gameRules->isGameComplete($match)) {
                $this->finalize($match);
            } else if ($this->gameRules->shouldStartNewRound($match)) {
                $this->startNewRound($match);
            }
        }

        return $actionResult;
    }
}
```

---

## Flujo Completo

```
1. Jugador hace acción
   ↓
2. RoundRules valida acción
   ↓
3. GameEngine procesa acción
   ↓
4. RoundRules decide si termina ronda
   ↓
5. Si termina ronda:
   - RoundRules calcula puntos de la ronda
   - GameEngine emite RoundEndedEvent
   ↓
6. GameRules decide si termina juego
   ↓
7. Si NO terminó:
   - GameRules decide si inicia nueva ronda
   - GameEngine emite RoundStartedEvent
   ↓
8. Si terminó:
   - GameRules calcula ganador
   - GameEngine emite GameFinishedEvent
```

---

## Ventajas de esta Arquitectura

1. **Separación Clara**:
   - Reglas de ronda en un archivo
   - Reglas de juego en otro archivo
   - GameEngine solo coordina

2. **Fácil de Entender**:
   - "¿Cómo se gana una ronda?" → Ver RoundRules
   - "¿Cómo se gana el juego?" → Ver GameRules

3. **Reutilizable**:
   - Varios juegos pueden compartir GameRules (ej: "primero en 5 puntos")
   - Varios juegos pueden compartir RoundRules (ej: "responder en X segundos")

4. **Fácil de Testear**:
   - Test unitario de RoundRules sin tocar GameEngine
   - Test unitario de GameRules sin tocar GameEngine

5. **Fácil de Modificar**:
   - Cambiar condición de victoria: solo modificar GameRules
   - Cambiar puntuación por ronda: solo modificar RoundRules

---

## Ejemplo Comparativo

### Trivia
- **GameRules**: "10 preguntas, gana quien tenga más puntos"
- **RoundRules**: "Primera respuesta correcta termina la ronda, +100 puntos + bonus velocidad"

### Pictionary
- **GameRules**: "Primero en 5 puntos gana"
- **RoundRules**: "Primera adivinación correcta termina la ronda, +1 punto al dibujante y adivinador"

### UNO
- **GameRules**: "Primero en quedarse sin cartas gana"
- **RoundRules**: "Turno termina cuando juegas una carta válida o robas"

---

## Próximos Pasos

1. Crear clases base `GameRules` y `RoundRules` abstractas
2. Implementar `TriviaGameRules` y `TriviaRoundRules`
3. Refactorizar `TriviaEngine` para usar estas clases
4. Aplicar mismo patrón a Pictionary
5. Documentar convenciones para crear nuevos juegos
