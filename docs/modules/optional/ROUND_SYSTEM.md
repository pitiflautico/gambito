# Round System (Módulo Opcional)

**Estado:** 📝 Plantilla (pendiente implementación)
**Tipo:** Opcional (configurable por juego)
**Prioridad:** 🔥 MVP (necesario para Pictionary)
**Última actualización:** 2025-10-21

---

## 📋 Descripción

El **Round System** es un módulo opcional que gestiona el sistema de rondas en los juegos. Controla cuántas rondas dura una partida y permite dos modos de configuración:

1. **Rondas fijas:** Número predefinido en la configuración del juego
2. **Rondas configurables:** El master de la sala elige el número al crear la partida

## 🎯 Responsabilidades

- Gestionar el contador de rondas (actual/total)
- Validar el número de rondas según configuración del juego
- Determinar cuándo termina la partida (condición de fin por rondas)
- Permitir configuración flexible por el master de la sala
- Registrar historial de rondas en `match_events`

## 🎯 Cuándo Usarlo

**Este módulo es útil para juegos que:**
- Tienen un número fijo de rondas (ej: Pictionary con 5 rondas)
- Permiten al master elegir cuántas rondas jugar
- Necesitan controlar el progreso de la partida por rondas
- Combinan rondas con turnos (cada jugador dibuja una vez = 1 ronda)

**NO es necesario para:**
- Juegos sin concepto de rondas
- Juegos que terminan por otra condición (puntos, tiempo)
- Juegos de una sola ronda

---

## 📦 Componentes

### Configuración en `game_state`

```php
[
    'round' => 1,              // Ronda actual (empieza en 1)
    'rounds_total' => 5,       // Total de rondas configuradas
    'rounds_completed' => [],  // Array de rondas completadas con metadatos
]
```

### Configuración en `config.json` del juego

```json
{
  "rounds": {
    "mode": "configurable",  // "fixed" o "configurable"
    "default": 5,            // Valor por defecto
    "min": 3,                // Mínimo permitido (si es configurable)
    "max": 10                // Máximo permitido (si es configurable)
  }
}
```

---

## 🎮 Casos de Uso

### Caso 1: Rondas Fijas (Modo Simple)

**Ejemplo:** Un juego de trivia siempre tiene exactamente 10 rondas.

```json
// config.json
{
  "rounds": {
    "mode": "fixed",
    "default": 10
  }
}
```

**Comportamiento:**
- El master NO puede cambiar el número de rondas
- Siempre serán 10 rondas exactas
- Se muestra "Ronda X de 10" en la UI

---

### Caso 2: Rondas Configurables (Modo Flexible)

**Ejemplo:** Pictionary permite al master elegir entre 3 y 10 rondas.

```json
// config.json
{
  "rounds": {
    "mode": "configurable",
    "default": 5,
    "min": 3,
    "max": 10
  }
}
```

**Comportamiento:**
- Al crear la sala, el master ve un selector
- Puede elegir entre 3, 4, 5, 6, 7, 8, 9 o 10 rondas
- El valor se guarda en `room.settings.rounds_total`
- Se inicializa en `game_state.rounds_total`

---

### Caso 3: Sin Límite de Rondas (Modo Infinito)

**Ejemplo:** Un juego que termina solo cuando alguien llega a 1000 puntos.

```json
// config.json
{
  "rounds": {
    "mode": "unlimited"
  }
}
```

**Comportamiento:**
- No se usa el Round System
- El juego termina por otra condición (puntos, tiempo, etc.)
- Se muestra "Ronda X" sin total

---

## 💻 Implementación

### Ejemplo en Pictionary

**1. Configuración del juego:**

`games/pictionary/config.json`
```json
{
  "name": "Pictionary",
  "rounds": {
    "mode": "configurable",
    "default": 5,
    "min": 3,
    "max": 10
  }
}
```

**2. Inicialización en el Engine:**

`PictionaryEngine.php`
```php
public function initialize(GameMatch $match): void
{
    $room = $match->room;
    $roundsConfig = $this->getGameConfig()['rounds'];

    // Obtener número de rondas desde settings de la sala
    $roundsTotal = $room->settings['rounds_total'] ?? $roundsConfig['default'];

    // Validar rango si es configurable
    if ($roundsConfig['mode'] === 'configurable') {
        $roundsTotal = max($roundsConfig['min'], min($roundsConfig['max'], $roundsTotal));
    }

    $match->game_state = [
        'round' => 0,  // Empieza en 0, incrementa a 1 al iniciar primera ronda
        'rounds_total' => $roundsTotal,
        'rounds_completed' => [],
        // ... otros campos
    ];

    $match->save();
}
```

**3. Avanzar ronda:**

```php
private function nextRound(GameMatch $match): void
{
    $gameState = $match->game_state;

    // Guardar metadatos de la ronda completada
    $gameState['rounds_completed'][] = [
        'round' => $gameState['round'],
        'winner' => $this->getRoundWinner($match),
        'duration' => $this->calculateRoundDuration($gameState),
        'completed_at' => now()->toDateTimeString(),
    ];

    // Incrementar ronda
    $gameState['round']++;

    $match->game_state = $gameState;
    $match->save();
}
```

**4. Verificar fin de partida:**

```php
private function isGameFinished(GameMatch $match): bool
{
    $gameState = $match->game_state;

    // Verificar si se completaron todas las rondas
    return $gameState['round'] >= $gameState['rounds_total'];
}
```

---

## 🔗 Integración con Otros Módulos

### Con Turn System

Muchos juegos combinan rondas y turnos:

```
Ronda 1:
  - Turno 1: Jugador A dibuja
  - Turno 2: Jugador B dibuja
  - Turno 3: Jugador C dibuja

Ronda 2:
  - Turno 1: Jugador A dibuja
  - Turno 2: Jugador B dibuja
  - Turno 3: Jugador C dibuja

...

Fin cuando round >= rounds_total
```

**Lógica:**
```php
private function nextTurn(GameMatch $match): void
{
    $gameState = $match->game_state;

    $currentTurn = $gameState['current_turn'];
    $nextTurn = ($currentTurn + 1) % count($gameState['turn_order']);

    // Si volvemos al primer jugador, incrementar ronda
    if ($nextTurn === 0) {
        $gameState['round']++;
    }

    $gameState['current_turn'] = $nextTurn;
    $match->game_state = $gameState;
    $match->save();
}
```

### Con Scoring System

El Round System puede usarse como condición de fin en combinación con puntuación:

```php
public function checkWinCondition(GameMatch $match): ?Player
{
    $gameState = $match->game_state;

    // Solo verificar ganador si se completaron todas las rondas
    if ($gameState['round'] < $gameState['rounds_total']) {
        return null;
    }

    // Encontrar jugador con más puntos
    return $this->getTopPlayer($match);
}
```

---

## 🎨 UI/UX

### Selector de rondas al crear sala (Master)

```
┌────────────────────────────────────┐
│  Configurar Partida - Pictionary   │
├────────────────────────────────────┤
│                                    │
│  Número de rondas:                 │
│  ┌──────────────────────────────┐  │
│  │  [3] [4] [●5] [6] [7] [8]    │  │
│  │         [9] [10]              │  │
│  └──────────────────────────────┘  │
│                                    │
│  ℹ️ Cada jugador dibujará 5 veces │
│                                    │
│  [Crear Sala]                      │
└────────────────────────────────────┘
```

### Indicador de progreso durante partida

```
┌────────────────────────────────────┐
│  Ronda 3 de 5                   ⏱ │
│  ████████░░░░░░░░░░  60%          │
└────────────────────────────────────┘
```

---

## 📊 Métricas

El Round System puede registrar:

- Duración promedio de cada ronda
- Ronda con más puntos otorgados
- Ronda más rápida/lenta
- Abandonos por ronda

Ejemplo en `MatchEvent`:
```php
MatchEvent::log($match, 'round_completed', [
    'round' => 3,
    'duration_seconds' => 180,
    'winner' => 'Player 1',
    'total_points_awarded' => 350,
]);
```

---

## 🧪 Testing

### Tests unitarios

```php
public function test_rounds_are_initialized_from_room_settings()
{
    $room = Room::factory()->create([
        'settings' => ['rounds_total' => 7]
    ]);

    $engine->initialize($match);

    $this->assertEquals(7, $match->game_state['rounds_total']);
}

public function test_configurable_rounds_are_clamped_to_valid_range()
{
    $room = Room::factory()->create([
        'settings' => ['rounds_total' => 999]  // Muy alto
    ]);

    $engine->initialize($match);

    $this->assertEquals(10, $match->game_state['rounds_total']); // Max = 10
}

public function test_game_ends_when_all_rounds_completed()
{
    $match->game_state = [
        'round' => 5,
        'rounds_total' => 5,
    ];

    $this->assertTrue($engine->isGameFinished($match));
}
```

---

## 🔄 Extracción desde Pictionary (Fase 4)

### Código actual en Pictionary (monolítico)

```php
// En PictionaryEngine.php
$match->game_state = [
    'round' => 0,
    'rounds_total' => 5,  // Hardcoded
    // ...
];
```

### Futuro Round System (modular)

```php
// app/Modules/RoundSystem/RoundManager.php
class RoundManager
{
    public function initialize(GameMatch $match, array $config): void
    {
        $roundsTotal = $this->resolveRoundsTotal($match->room, $config);

        $match->game_state['round'] = 0;
        $match->game_state['rounds_total'] = $roundsTotal;
        $match->game_state['rounds_completed'] = [];
    }

    public function nextRound(GameMatch $match): void { /* ... */ }
    public function isFinished(GameMatch $match): bool { /* ... */ }
    public function getCurrentRound(GameMatch $match): int { /* ... */ }
}
```

**Uso en juegos:**

```php
// En PictionaryEngine.php
use App\Modules\RoundSystem\RoundManager;

public function initialize(GameMatch $match): void
{
    $roundManager = new RoundManager();
    $roundManager->initialize($match, $this->config['rounds']);

    // ... resto de inicialización
}
```

---

## 📚 Referencias

- **Turn System:** [`docs/modules/optional/TURN_SYSTEM.md`](TURN_SYSTEM.md)
- **Scoring System:** [`docs/modules/optional/SCORING_SYSTEM.md`](SCORING_SYSTEM.md)
- **Pictionary:** [`docs/games/PICTIONARY.md`](../../games/PICTIONARY.md)
- **ADR-002:** Desarrollo Iterativo

---

## 🚀 Próximos Pasos

1. **Fase 3 (Pictionary MVP):** Implementado monolíticamente en `PictionaryEngine`
2. **Fase 4 (Extracción):** Crear módulo `RoundManager` reutilizable
3. **Fase 5 (Validación):** Usar en segundo juego (Trivia/UNO)

---

**Mantenido por:** Equipo de desarrollo Gambito
**Última actualización:** 2025-10-21
