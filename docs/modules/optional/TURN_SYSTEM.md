# Turn System (Módulo Opcional)

**Estado:** ✅ Implementado
**Tipo:** Opcional (configurable)
**Versión:** 1.0.0
**Última actualización:** 2025-10-21

---

## 📋 Descripción

El **Turn System** es un módulo opcional que gestiona el orden y rotación de turnos en juegos. Proporciona una API flexible para controlar quién juega, cuándo y en qué orden, soportando múltiples modos (secuencial, aleatorio, simultáneo, libre).

## 🎯 Responsabilidades

- Crear y mantener el orden de turnos entre jugadores
- Rotar turnos de forma circular (al terminar el último jugador, vuelve al primero)
- Gestionar rondas (incrementar automáticamente cuando todos han jugado)
- Detectar cuándo termina el juego (si hay límite de rondas)
- Manejar dinámicamente la adición/eliminación de jugadores
- Permitir consultas de estado (jugador actual, ronda, índice, etc.)
- Serializar/deserializar el estado para persistencia en base de datos

## 🎯 Cuándo Usarlo

**Cuando el juego necesite control de turnos.** Por ejemplo:

- **Pictionary:** Turnos secuenciales donde cada jugador dibuja una vez por ronda
- **UNO:** Turnos secuenciales con posibilidad de reversión y saltos
- **Trivia:** Turnos simultáneos donde todos responden a la vez
- **Juegos de mesa:** Cualquier juego con rotación de jugadores

**NO es necesario para:**
- Juegos sin turnos definidos
- Juegos completamente en tiempo real sin estructura de turnos
- Juegos donde todos los jugadores actúan simultáneamente sin rotación

---

## ⚙️ Configuración

### En `capabilities.json` del juego:

```json
{
  "turn_system": {
    "enabled": true,
    "mode": "sequential",
    "total_rounds": 5
  }
}
```

**Opciones disponibles:**
- `enabled` (bool): Activar/desactivar módulo
- `mode` (string): Modo de turnos
  - `"sequential"`: Orden original de jugadores
  - `"shuffle"`: Orden aleatorio al inicio
  - `"simultaneous"`: Todos juegan a la vez
  - `"free"`: Sin orden específico
- `total_rounds` (int): Número total de rondas (0 = infinitas)

### Ejemplo completo (Pictionary):

```json
{
  "slug": "pictionary",
  "requires": {
    "turn_system": {
      "enabled": true,
      "mode": "shuffle",
      "total_rounds": 5
    }
  }
}
```

---

## 🔧 API / Servicios

### Clase Principal: `TurnManager`

**Ubicación:** `app/Services/Modules/TurnSystem/TurnManager.php`

**Responsabilidad:** Gestionar el sistema de turnos de forma independiente y reutilizable.

---

### Constructor

#### `__construct(array $playerIds, string $mode = 'sequential', int $totalRounds = 0, int $startingRound = 1)`

Inicializa el sistema de turnos.

**Parámetros:**
- `$playerIds` (array): Array de IDs de jugadores (mínimo 1)
- `$mode` (string): Modo de turnos ('sequential', 'shuffle', 'simultaneous', 'free')
- `$totalRounds` (int): Total de rondas (0 = infinitas)
- `$startingRound` (int): Ronda inicial (default: 1)

**Ejemplo:**
```php
$turnManager = new TurnManager(
    playerIds: [48, 49, 50],
    mode: 'shuffle',
    totalRounds: 5,
    startingRound: 1
);
```

---

### Métodos de Consulta

#### `getCurrentPlayer(): mixed`

Obtiene el ID del jugador que tiene el turno actual.

**Retorna:** ID del jugador actual

**Ejemplo:**
```php
$currentPlayerId = $turnManager->getCurrentPlayer();
// Retorna: 49
```

---

#### `getCurrentTurnIndex(): int`

Obtiene el índice del turno actual (0-based).

**Retorna:** int - Índice del turno

**Ejemplo:**
```php
$index = $turnManager->getCurrentTurnIndex();
// Retorna: 1 (segundo jugador)
```

---

#### `getCurrentRound(): int`

Obtiene el número de ronda actual (1-based).

**Retorna:** int - Ronda actual

**Ejemplo:**
```php
$round = $turnManager->getCurrentRound();
// Retorna: 3 (tercera ronda)
```

---

#### `getTurnOrder(): array`

Obtiene el orden completo de turnos.

**Retorna:** array - Array de IDs de jugadores

**Ejemplo:**
```php
$order = $turnManager->getTurnOrder();
// Retorna: [49, 50, 48]
```

---

#### `getCurrentTurnInfo(): array`

Obtiene toda la información del turno actual.

**Retorna:** array con estructura:
```php
[
    'player_id' => mixed,       // ID del jugador actual
    'turn_index' => int,        // Índice del turno (0-based)
    'round' => int,             // Ronda actual (1-based)
    'round_completed' => bool,  // Si se completó una ronda en el último nextTurn()
    'game_complete' => bool,    // Si el juego terminó (todas las rondas completadas)
]
```

**Ejemplo:**
```php
$info = $turnManager->getCurrentTurnInfo();
// Retorna:
// [
//     'player_id' => 49,
//     'turn_index' => 1,
//     'round' => 2,
//     'round_completed' => false,
//     'game_complete' => false
// ]
```

---

#### `isPlayerTurn(mixed $playerId): bool`

Verifica si es el turno de un jugador específico.

**Parámetros:**
- `$playerId` (mixed): ID del jugador a verificar

**Retorna:** bool - True si es su turno

**Ejemplo:**
```php
if ($turnManager->isPlayerTurn(49)) {
    echo "Es tu turno!";
}
```

---

#### `peekNextPlayer(): mixed`

Obtiene el ID del siguiente jugador SIN avanzar el turno.

**Retorna:** mixed - ID del siguiente jugador

**Ejemplo:**
```php
$nextPlayer = $turnManager->peekNextPlayer();
echo "El siguiente es el jugador {$nextPlayer}";
```

---

#### `getPlayerCount(): int`

Obtiene el total de jugadores activos.

**Retorna:** int - Número de jugadores

**Ejemplo:**
```php
$total = $turnManager->getPlayerCount();
// Retorna: 3
```

---

#### `getMode(): string`

Obtiene el modo de turnos configurado.

**Retorna:** string - 'sequential', 'shuffle', 'simultaneous', 'free'

**Ejemplo:**
```php
$mode = $turnManager->getMode();
// Retorna: 'shuffle'
```

---

### Métodos de Acción

#### `nextTurn(): array`

Avanza al siguiente turno. Rotación circular: al llegar al último jugador, vuelve al primero e incrementa la ronda.

**Retorna:** array - Información del nuevo turno (mismo formato que `getCurrentTurnInfo()`)

**Ejemplo:**
```php
$info = $turnManager->nextTurn();
// Retorna:
// [
//     'player_id' => 50,
//     'turn_index' => 2,
//     'round' => 2,
//     'round_completed' => false,
//     'game_complete' => false
// ]
```

---

#### `isNewRound(): bool`

Verifica si en el último `nextTurn()` se completó una ronda.

**Retorna:** bool - True si se completó una ronda

**Ejemplo:**
```php
$turnManager->nextTurn();
$turnManager->nextTurn();
$turnManager->nextTurn(); // Último jugador → vuelve al primero

if ($turnManager->isNewRound()) {
    echo "¡Nueva ronda iniciada!";
}
```

---

#### `isGameComplete(): bool`

Verifica si el juego ha terminado (todas las rondas completadas).

**Retorna:** bool - True si se completaron todas las rondas

**Ejemplo:**
```php
if ($turnManager->isGameComplete()) {
    echo "¡Juego terminado!";
    $this->finalize();
}
```

---

### Métodos de Gestión de Jugadores

#### `removePlayer(mixed $playerId): bool`

Elimina un jugador del orden de turnos. Ajusta automáticamente los índices.

**Parámetros:**
- `$playerId` (mixed): ID del jugador a eliminar

**Retorna:** bool - True si se eliminó, false si no existía

**Ejemplo:**
```php
$removed = $turnManager->removePlayer(48);
if ($removed) {
    echo "Jugador eliminado";
}
```

**IMPORTANTE:** Si se elimina un jugador antes del turno actual, se ajusta el índice automáticamente.

---

#### `addPlayer(mixed $playerId): void`

Agrega un jugador al final del orden de turnos.

**Parámetros:**
- `$playerId` (mixed): ID del jugador a agregar

**Ejemplo:**
```php
$turnManager->addPlayer(51); // Nuevo jugador se agrega al final
```

---

### Métodos de Utilidad

#### `reset(int $startingRound = 1): void`

Reinicia el sistema de turnos. Vuelve al turno 0 y ronda inicial. NO modifica el orden de jugadores.

**Parámetros:**
- `$startingRound` (int): Ronda de inicio (default: 1)

**Ejemplo:**
```php
$turnManager->reset(); // Vuelve al principio
```

---

#### `toArray(): array`

Exporta el estado actual a un array. Útil para guardar en `game_state` JSON.

**Retorna:** array con estructura:
```php
[
    'turn_order' => array,
    'current_turn_index' => int,
    'current_round' => int,
    'total_rounds' => int,
    'mode' => string,
]
```

**Ejemplo:**
```php
$state = $turnManager->toArray();
$match->game_state = array_merge($match->game_state, $state);
$match->save();
```

---

#### `static fromArray(array $state): TurnManager`

Crea una instancia desde un array guardado previamente con `toArray()`.

**Parámetros:**
- `$state` (array): Estado previamente guardado

**Retorna:** TurnManager - Nueva instancia restaurada

**Ejemplo:**
```php
$turnManager = TurnManager::fromArray($match->game_state);
```

---

## 💡 Ejemplos de Uso

### Ejemplo 1: Inicialización en un GameEngine

```php
use App\Services\Modules\TurnSystem\TurnManager;

public function initialize(GameMatch $match): void
{
    $players = $match->players()->where('is_connected', true)->get();
    $playerIds = $players->pluck('id')->toArray();

    // Crear TurnManager con orden aleatorio
    $turnManager = new TurnManager(
        playerIds: $playerIds,
        mode: 'shuffle',
        totalRounds: 5,
        startingRound: 1
    );

    // Guardar estado en game_state
    $match->game_state = array_merge([
        'phase' => 'playing',
        'current_drawer_id' => $turnManager->getCurrentPlayer(),
    ], $turnManager->toArray());

    $match->save();
}
```

---

### Ejemplo 2: Avanzar turno en Pictionary

```php
private function nextTurn(GameMatch $match): void
{
    // Restaurar TurnManager desde game_state
    $turnManager = TurnManager::fromArray($match->game_state);

    // Avanzar turno
    $turnInfo = $turnManager->nextTurn();

    // Actualizar game_state
    $gameState = $match->game_state;
    $gameState = array_merge($gameState, $turnManager->toArray());
    $gameState['current_drawer_id'] = $turnInfo['player_id'];

    // Si se completó una ronda
    if ($turnInfo['round_completed']) {
        Log::info("Nueva ronda iniciada", ['round' => $turnInfo['round']]);
    }

    // Si el juego terminó
    if ($turnInfo['game_complete']) {
        $gameState['phase'] = 'results';
    }

    $match->game_state = $gameState;
    $match->save();
}
```

---

### Ejemplo 3: Verificar si es el turno de un jugador

```php
public function canDraw(GameMatch $match, Player $player): bool
{
    $turnManager = TurnManager::fromArray($match->game_state);
    return $turnManager->isPlayerTurn($player->id);
}
```

---

### Ejemplo 4: Eliminar jugador desconectado

```php
public function handlePlayerDisconnect(GameMatch $match, Player $player): void
{
    $turnManager = TurnManager::fromArray($match->game_state);

    // Eliminar jugador del sistema de turnos
    $removed = $turnManager->removePlayer($player->id);

    if ($removed) {
        // Actualizar game_state
        $match->game_state = array_merge(
            $match->game_state,
            $turnManager->toArray()
        );
        $match->save();

        Log::info("Jugador eliminado del sistema de turnos", [
            'player_id' => $player->id,
            'remaining_players' => $turnManager->getPlayerCount()
        ]);
    }
}
```

---

## 🧪 Tests

**Ubicación:**
- Unit: `tests/Unit/Services/Modules/TurnSystem/TurnManagerTest.php`

**Tests implementados:**
- ✅ Inicialización con modo sequential
- ✅ Inicialización con modo shuffle
- ✅ Rotación de turnos (nextTurn)
- ✅ Detección de nueva ronda
- ✅ Detección de fin de juego
- ✅ Obtener jugador actual
- ✅ Verificar turno de jugador específico
- ✅ Eliminar jugador (con ajuste de índices)
- ✅ Agregar jugador
- ✅ Reiniciar sistema
- ✅ Serialización (toArray/fromArray)
- ✅ Peek siguiente jugador

**Ejecutar tests:**
```bash
php artisan test --filter=TurnManagerTest
php artisan test tests/Unit/Services/Modules/TurnSystem/TurnManagerTest.php
```

**Ejemplo de test:**
```php
public function test_next_turn_advances_to_next_player()
{
    // Arrange
    $turnManager = new TurnManager([1, 2, 3], 'sequential', 5);

    // Act
    $info = $turnManager->nextTurn();

    // Assert
    $this->assertEquals(2, $info['player_id']);
    $this->assertEquals(1, $info['turn_index']);
    $this->assertEquals(1, $info['round']);
    $this->assertFalse($info['round_completed']);
}

public function test_completing_round_increments_round_number()
{
    // Arrange
    $turnManager = new TurnManager([1, 2, 3], 'sequential', 5);

    // Act - Completar ronda (3 turnos)
    $turnManager->nextTurn(); // Jugador 2
    $turnManager->nextTurn(); // Jugador 3
    $info = $turnManager->nextTurn(); // Vuelve a Jugador 1

    // Assert
    $this->assertEquals(1, $info['player_id']);
    $this->assertEquals(0, $info['turn_index']);
    $this->assertEquals(2, $info['round']);
    $this->assertTrue($info['round_completed']);
}
```

---

## 📦 Dependencias

### Internas:
- Ninguna (módulo completamente independiente)

### Externas:
- `illuminate/support` (Collection) - Para manipulación de arrays

### Módulos Opcionales:
- Ninguna dependencia de otros módulos

**Este módulo es completamente independiente y puede usarse sin otros módulos.**

---

## 🚨 Limitaciones Conocidas

- **No soporta turnos paralelos dentro de un mismo turno** (ejemplo: sub-turnos o fases dentro de un turno)
- **No incluye historial de turnos** (no guarda quién jugó cuándo)
- **No soporta ponderación de turnos** (ejemplo: jugador A juega 2 veces, jugador B juega 1 vez)

## 🔮 Mejoras Futuras

- [ ] **Skip Turn:** Saltar turno de un jugador sin eliminarlo
- [ ] **Pause/Resume:** Pausar y reanudar el sistema de turnos
- [ ] **Reverse Order:** Invertir dirección de turnos (útil para UNO)
- [ ] **Turn History:** Guardar historial de turnos jugados
- [ ] **Weighted Turns:** Soporte para turnos ponderados
- [ ] **Sub-turns:** Fases dentro de cada turno
- [ ] **Dynamic Duration:** Duración variable por turno/jugador

---

## 🔗 Referencias

- **Código:** [`app/Services/Modules/TurnSystem/TurnManager.php`](../../../app/Services/Modules/TurnSystem/TurnManager.php)
- **Tests:** [`tests/Unit/Services/Modules/TurnSystem/TurnManagerTest.php`](../../../tests/Unit/Services/Modules/TurnSystem/TurnManagerTest.php)
- **Glosario:** [`docs/GLOSSARY.md`](../../GLOSSARY.md#turn-system)
- **PRD:** [`tasks/0001-prd-plataforma-juegos-sociales.md`](../../../tasks/0001-prd-plataforma-juegos-sociales.md)
- **Módulos relacionados:**
  - [`docs/modules/optional/ROUND_SYSTEM.md`](ROUND_SYSTEM.md)
  - [`docs/modules/optional/SCORING_SYSTEM.md`](SCORING_SYSTEM.md)
- **Juegos que lo usan:**
  - [`docs/games/PICTIONARY.md`](../../games/PICTIONARY.md)

---

**Mantenido por:** Todo el equipo de desarrollo
**Última revisión:** 2025-10-21
