# Scoring System Module

**Estado:** ✅ Implementado
**Tipo:** Opcional (configurable)
**Versión:** 1.0
**Autor:** Claude Code
**Fecha:** 2025-10-21

---

## 📋 Descripción

Módulo genérico para gestionar puntuaciones en juegos competitivos. Utiliza el patrón Strategy para delegar el cálculo de puntos específico a cada juego, mientras mantiene la lógica de gestión (acumulación, rankings, estadísticas) de forma reutilizable.

**Componentes:**
- `ScoreManager`: Gestor genérico de puntuaciones
- `ScoreCalculatorInterface`: Interface que cada juego debe implementar
- `[Game]ScoreCalculator`: Implementación específica por juego

---

## 🎯 Cuándo Usarlo

Usa este módulo cuando tu juego necesite:

- ✅ Sistema de puntuación competitivo
- ✅ Rankings de jugadores
- ✅ Cálculo de ganadores
- ✅ Estadísticas de partida
- ✅ Diferentes criterios de puntuación según contexto

**Ejemplos de juegos:**
- **Pictionary**: Puntos por velocidad de respuesta
- **Trivia**: Puntos por dificultad de pregunta + tiempo
- **UNO**: Puntos por valor de cartas restantes
- **Estrategia**: Puntos por recursos, territorios, objetivos

---

## ⚙️ Configuración

### En capabilities.json del juego

```json
{
  "slug": "my-game",
  "version": "1.0",
  "requires": {
    "modules": {
      "scoring_system": "^1.0"
    }
  }
}
```

---

## 🔧 API / Servicios

### ScoreManager

Clase principal que gestiona las puntuaciones de todos los jugadores.

#### Constructor

```php
public function __construct(
    array $playerIds,                      // IDs de jugadores
    ScoreCalculatorInterface $calculator,  // Calculador específico del juego
    bool $trackHistory = false             // Si registrar historial
)
```

#### Métodos Principales

**Otorgar puntos:**
```php
public function awardPoints(
    int $playerId,
    string $eventType,
    array $context = []
): int
```

**Quitar puntos (penalización):**
```php
public function deductPoints(int $playerId, int $points): int
```

**Establecer puntuación directa:**
```php
public function setScore(int $playerId, int $score): void
```

**Obtener puntuación:**
```php
public function getScore(int $playerId): int
public function getScores(): array  // Todas las puntuaciones
```

**Rankings:**
```php
public function getRanking(): array  // Ordenado por puntuación
public function getWinner(): ?array  // Ganador único (null si hay empate)
public function getWinners(): array  // Todos los ganadores (maneja empates)
```

**Estadísticas:**
```php
public function getStatistics(): array
// Retorna: total_players, total_points, average_score, highest_score, lowest_score
```

**Gestión dinámica:**
```php
public function addPlayer(int $playerId, int $initialScore = 0): void
public function removePlayer(int $playerId): int
public function reset(): void
```

**Serialización:**
```php
public function toArray(): array
public static function fromArray(array $playerIds, array $data, ScoreCalculatorInterface $calculator, bool $trackHistory = false): self
```

---

### ScoreCalculatorInterface

Interface que cada juego debe implementar con su lógica de cálculo.

```php
interface ScoreCalculatorInterface
{
    /**
     * Calcular puntos para un evento específico.
     *
     * @param string $eventType Tipo de evento (ej: 'correct_answer', 'drawer_bonus')
     * @param array $context Contexto del evento (tiempo, dificultad, etc.)
     * @return int Puntos calculados
     */
    public function calculate(string $eventType, array $context): int;

    /**
     * Obtener configuración de puntuación.
     */
    public function getConfig(): array;

    /**
     * Validar si un evento es válido.
     */
    public function supportsEvent(string $eventType): bool;
}
```

---

## 💡 Ejemplos de Uso

### Ejemplo 1: Pictionary (basado en tiempo)

**PictionaryScoreCalculator.php:**
```php
class PictionaryScoreCalculator implements ScoreCalculatorInterface
{
    protected array $guesserPoints = [
        'fast' => 150,    // 0-33% del tiempo
        'normal' => 100,  // 34-67%
        'slow' => 50,     // 68-100%
        'timeout' => 0
    ];

    protected array $drawerPoints = [
        'fast' => 75,
        'normal' => 50,
        'slow' => 25,
        'timeout' => 0
    ];

    public function calculate(string $eventType, array $context): int
    {
        return match ($eventType) {
            'correct_answer' => $this->calculateGuesserPoints($context),
            'drawer_bonus' => $this->calculateDrawerPoints($context),
            default => throw new \InvalidArgumentException("Evento no soportado"),
        };
    }

    protected function calculateGuesserPoints(array $context): int
    {
        $secondsElapsed = $context['seconds_elapsed'];
        $maxTime = $context['turn_duration'];

        $speed = $this->getSpeedCategory($secondsElapsed, $maxTime);
        return $this->guesserPoints[$speed];
    }
}
```

**Uso en PictionaryEngine:**
```php
// Inicialización
$scoreCalculator = new PictionaryScoreCalculator();
$scoreManager = new ScoreManager($playerIds, $scoreCalculator);

// En game_state
$match->game_state = array_merge([
    'phase' => 'playing',
    // ... otros campos
], $scoreManager->toArray());

// Otorgar puntos cuando alguien acierta
$guesserPoints = $scoreManager->awardPoints($guesserPlayerId, 'correct_answer', [
    'seconds_elapsed' => 25,
    'turn_duration' => 90,
]);

$drawerPoints = $scoreManager->awardPoints($drawerId, 'drawer_bonus', [
    'seconds_elapsed' => 25,
    'turn_duration' => 90,
]);

// Actualizar game_state
$gameState['scores'] = $scoreManager->getScores();
```

### Ejemplo 2: Trivia (basado en dificultad + tiempo)

```php
class TriviaScoreCalculator implements ScoreCalculatorInterface
{
    protected array $basePoints = [
        'easy' => 10,
        'medium' => 20,
        'hard' => 30,
    ];

    public function calculate(string $eventType, array $context): int
    {
        if ($eventType !== 'correct_answer') {
            return 0;
        }

        $difficulty = $context['difficulty'];
        $secondsElapsed = $context['seconds_elapsed'];
        $maxTime = $context['max_time'];

        // Puntos base por dificultad
        $basePoints = $this->basePoints[$difficulty];

        // Multiplicador por velocidad (1.5x si < 50% tiempo, 1.0x si >= 50%)
        $speedMultiplier = ($secondsElapsed < $maxTime * 0.5) ? 1.5 : 1.0;

        return (int) ($basePoints * $speedMultiplier);
    }
}
```

### Ejemplo 3: UNO (basado en cartas)

```php
class UNOScoreCalculator implements ScoreCalculatorInterface
{
    protected array $cardValues = [
        'number' => 1,      // Cartas numéricas: valor de la carta
        'skip' => 20,
        'reverse' => 20,
        'draw_two' => 20,
        'wild' => 50,
        'wild_draw_four' => 50,
    ];

    public function calculate(string $eventType, array $context): int
    {
        if ($eventType !== 'round_win') {
            return 0;
        }

        $opponentCards = $context['opponent_cards']; // Array de cartas
        $totalPoints = 0;

        foreach ($opponentCards as $card) {
            if ($card['type'] === 'number') {
                $totalPoints += $card['value'];
            } else {
                $totalPoints += $this->cardValues[$card['type']];
            }
        }

        return $totalPoints;
    }
}
```

---

## 🧪 Tests

### Tests de ScoreManager (Genérico)

**Ubicación:** `tests/Unit/Services/Modules/ScoringSystem/ScoreManagerTest.php`

**Cobertura:** 22 tests, 58 assertions

```bash
php artisan test --filter=ScoreManagerTest
```

**Tests incluidos:**
- ✅ Inicialización con jugadores
- ✅ Otorgar puntos y acumulación
- ✅ Deducción de puntos y prevención de negativos
- ✅ Rankings y ganadores (con empates)
- ✅ Estadísticas
- ✅ Gestión dinámica (add/remove players)
- ✅ Reset de puntuaciones
- ✅ Historial (cuando está activado)
- ✅ Serialización (toArray/fromArray)

### Tests de PictionaryScoreCalculator

**Ubicación:** `tests/Unit/Games/Pictionary/PictionaryScoreCalculatorTest.php`

**Cobertura:** 19 tests, 36 assertions

```bash
php artisan test --filter=PictionaryScoreCalculatorTest
```

**Tests incluidos:**
- ✅ Cálculo de puntos para adivinador (fast/normal/slow/timeout)
- ✅ Cálculo de puntos para dibujante
- ✅ Diferentes duraciones de turno
- ✅ Validación de eventos soportados
- ✅ Manejo de errores (campos faltantes)
- ✅ Personalización de puntos y umbrales
- ✅ Límites de umbrales correctos

---

## 📦 Dependencias

**Ninguna dependencia externa.**

El módulo es completamente standalone y solo requiere:
- PHP 8.0+ (para property promotion)
- Laravel (para helper `now()` en historial)

---

## 🔗 Referencias

- **Código:** `app/Services/Modules/ScoringSystem/`
- **Interface:** `app/Services/Modules/ScoringSystem/ScoreCalculatorInterface.php`
- **Implementación Pictionary:** `games/pictionary/PictionaryScoreCalculator.php`
- **Tests:** `tests/Unit/Services/Modules/ScoringSystem/`
- **Patrón usado:** Strategy Pattern
- **Task:** Task 10.0 - Extraer Scoring System Module

---

## 🎨 Patrón de Diseño

**Strategy Pattern:**

```
┌─────────────────────────┐
│    ScoreManager         │  ← Gestor genérico (context)
│  (gestiona scores)      │
└───────────┬─────────────┘
            │ usa
            ▼
┌─────────────────────────┐
│ ScoreCalculatorInterface│  ← Strategy interface
└───────────┬─────────────┘
            │
    ┌───────┴───────┬──────────────┬────────────┐
    ▼               ▼              ▼            ▼
┌─────────┐   ┌──────────┐  ┌──────────┐  ┌────────┐
│Pictionary│   │  Trivia  │  │   UNO    │  │  ...   │
│Calculator│   │Calculator│  │Calculator│  │        │
└──────────┘   └──────────┘  └──────────┘  └────────┘
```

**Ventajas:**
- ✅ ScoreManager es reutilizable al 100%
- ✅ Cada juego define su propia lógica de puntuación
- ✅ Fácil añadir nuevos juegos
- ✅ Testeable independientemente
- ✅ No hay duplicación de código

---

## ⚠️ Limitaciones

1. **Puntuaciones negativas:** Por defecto se evitan (mínimo = 0). Si necesitas permitirlas, modifica `deductPoints()`.

2. **Historial:** Desactivado por defecto para performance. Actívalo solo si necesitas replay/debug.

3. **Empates:** `getWinner()` retorna `null` si hay empate. Usa `getWinners()` para obtener todos los ganadores con máxima puntuación.

4. **Sincronización:** ScoreManager es stateless - debes serializar/deserializar desde `game_state` en cada operación.

---

## 🚀 Mejoras Futuras

- [ ] **Multiplicadores dinámicos:** Bonus por rachas, combos, etc.
- [ ] **Puntos por equipos:** Extensión para juegos con teams
- [ ] **Achievements:** Sistema de logros/badges
- [ ] **Leaderboards globales:** Persistir mejores puntuaciones
- [ ] **Decay de puntos:** Para juegos largos con mecánicas de deterioro

---

**Última actualización:** 21 de octubre de 2025
**Versión documentación:** 1.0
