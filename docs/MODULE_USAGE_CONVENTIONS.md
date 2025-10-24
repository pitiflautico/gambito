# Convenciones de Uso de Módulos en BaseGameEngine

## Resumen Ejecutivo

**REGLA DE ORO**: Los módulos (RoundManager, RoleManager, TurnManager, etc.) son la **ÚNICA fuente de verdad**. El `game_state` es solo su serialización para persistencia.

## Arquitectura

```
┌─────────────────────────────────────────────────────────────┐
│                    GameEngine (Pictionary, etc.)            │
│  - Implementa lógica ESPECÍFICA del juego                   │
│  - Usa métodos protected de BaseGameEngine                  │
│  - NO modifica game_state de módulos directamente           │
│  - NO implementa modos de juego (eso es de BaseGameEngine)  │
│  - Llama a parent::nextTurn(), parent::advancePhase(), etc. │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                      BaseGameEngine                          │
│  ⭐ AQUÍ SE IMPLEMENTAN LOS MODOS DE JUEGO ⭐                │
│  - Lee config.json para detectar modos (round_per_turn)     │
│  - Orquesta los módulos según el modo configurado           │
│  - Coordina: ronda, turno, player Y rol simultáneamente     │
│                                                              │
│  Ejemplo: round_per_turn                                    │
│  1. Lee config['modules']['turn_system']['round_per_turn']  │
│  2. Si true → llama roundManager.nextTurnWithRoundAdvance() │
│  3. Si false → llama roundManager.nextTurn() (normal)       │
│                                                              │
│  Métodos clave:                                             │
│  - getRoundManager($match) → obtiene módulo                 │
│  - nextTurn($match) → detecta modo y usa método apropiado  │
│  - saveRoundManager($match, $roundManager) → persiste       │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                    RoundManager (módulo)                     │
│  - Contiene lógica de negocio                               │
│  - Provee métodos para diferentes modos:                    │
│    * nextTurn() - modo normal (ciclo completo = ronda)      │
│    * nextTurnWithRoundAdvance() - round-per-turn            │
│  - Es inmutable desde fuera (no se modifica game_state)     │
│  - toArray() serializa para guardar en BD                   │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│              game_state (JSON en base de datos)              │
│  {                                                           │
│    "round_system": {...},  ← Serialización de RoundManager  │
│    "roles_system": {...},  ← Serialización de RoleManager   │
│    "turn_system": {...}    ← Serialización de TurnManager   │
│  }                                                           │
└─────────────────────────────────────────────────────────────┘
```

## ✅ Patrones CORRECTOS

### 1. Obtener y Modificar Módulos (DENTRO de GameEngine)

```php
// ✅ CORRECTO: Usar módulos
protected function nextTurn(GameMatch $match): array
{
    // 1. Obtener módulo
    $roundManager = $this->getRoundManager($match);

    // 2. Modificar módulo (si necesario)
    // $roundManager->nextTurn() ya se llama en parent::nextTurn()

    // 3. Llamar a parent que guarda automáticamente
    $turnInfo = parent::nextTurn($match);

    // 4. Lógica específica del juego (si necesario)
    $match->refresh(); // Importante: refrescar después de parent::nextTurn()
    $gameState = $match->game_state;
    $gameState['current_word'] = $this->selectNewWord();

    // 5. Guardar cambios específicos del juego
    $match->game_state = $gameState;
    $match->save();

    return $turnInfo;
}
```

### 2. Leer Estado (DENTRO de GameEngine)

```php
// ✅ CORRECTO: Usar helpers
protected function someMethod(GameMatch $match): void
{
    $currentRound = $this->getCurrentRound($match->game_state);
    $scores = $this->getScores($match->game_state);
    $currentPlayer = $this->getCurrentPlayer($match->game_state);
}
```

### 3. Tests y Código Externo

```php
// ✅ CORRECTO: Usar API pública y observar game_state
public function test_round_advances()
{
    // 1. Ejecutar acción pública
    $this->engine->processAction($match, $player, 'answer', []);

    // 2. Refrescar desde BD
    $match->refresh();

    // 3. Observar game_state
    $round = $match->game_state['round_system']['current_round'];

    // 4. Assert
    $this->assertEquals(2, $round);
}
```

## ❌ Anti-Patrones (EVITAR)

### 1. Modificar game_state Directamente

```php
// ❌ INCORRECTO: Modificación directa
protected function nextTurn(GameMatch $match): array
{
    $gameState = $match->game_state;
    $gameState['round_system']['current_round'] = 2; // ❌ NO HACER ESTO
    $match->game_state = $gameState;
    $match->save();
}

// ¿Por qué está mal?
// - Bypasea la lógica de negocio del módulo
// - Se pierde si luego se llama a saveRoundManager()
// - Rompe la sincronización entre módulos
```

### 2. Acceso Directo a Módulos desde Tests

```php
// ❌ INCORRECTO: Acceder a métodos protected
public function test_something()
{
    $roundManager = $this->engine->getRoundManager($match); // ❌ protected method
}

// ¿Por qué está mal?
// - getRoundManager() es protected por diseño
// - Los tests deben usar la API pública
// - Si necesitas verificar estado, usa game_state
```

### 3. Duplicar Lógica de Módulos

```php
// ❌ INCORRECTO: Duplicar lógica
protected function nextTurn(GameMatch $match): array
{
    // Incrementar manualmente
    $gameState = $match->game_state;
    $gameState['round_system']['current_round']++;
    $match->game_state = $gameState;
    $match->save();

    // Y luego llamar a parent que TAMBIÉN incrementa
    parent::nextTurn($match); // ❌ Duplicación
}
```

## Modos de Juego: Responsabilidad de BaseGameEngine

### 🎯 Concepto Clave
**Los MODOS DE JUEGO se implementan en BaseGameEngine, NO en los juegos individuales.**

Los modos de juego implican coordinación entre múltiples módulos (ronda, turno, player, rol). BaseGameEngine es el orquestador que:
1. Lee la configuración del juego (`config.json`)
2. Detecta el modo configurado
3. Llama a los métodos apropiados de los módulos

### Caso de Estudio: Round-Per-Turn Mode

**Definición**: En round-per-turn, cada turno es una ronda completa. Usado en juegos como Pictionary donde cada dibujante = 1 ronda.

#### ❌ Implementación INCORRECTA (NO hacer en GameEngine específico)
```php
// En PictionaryEngine::nextTurn() - ❌ INCORRECTO
protected function nextTurn(GameMatch $match): array
{
    $config = $this->getGameConfig();
    $roundPerTurn = $config['modules']['turn_system']['round_per_turn'] ?? false;

    if ($roundPerTurn) {
        // ❌ Modificar game_state directamente
        $gameState = $match->game_state;
        $gameState['round_system']['current_round']++;
        $match->game_state = $gameState;
        $match->save();
    }

    return parent::nextTurn($match);
}
```

**¿Por qué está mal?**
- Duplica lógica que debería estar en BaseGameEngine
- Modifica game_state directamente (bypasea módulos)
- El cambio se pierde cuando `parent::nextTurn()` llama a `saveRoundManager()`
- Cada juego tendría que reimplementar esta lógica

#### ✅ Implementación CORRECTA (ACTUAL)

**1. Configuración del juego** (`games/pictionary/config.json`):
```json
"modules": {
  "turn_system": {
    "enabled": true,
    "mode": "sequential",
    "round_per_turn": true  // ← Activar modo
  }
}
```

**2. BaseGameEngine detecta y actúa** (`app/Contracts/BaseGameEngine.php`):
```php
protected function nextTurn(GameMatch $match): array
{
    $gameState = $match->game_state;
    $roundManager = $this->getRoundManager($match);

    // Paso 1: Limpiar eliminaciones temporales (si aplica)
    if ($this->shouldClearTemporaryEliminations()) {
        $roundManager->clearTemporaryEliminations();
    }

    // Paso 2: Avanzar turno según el MODO configurado
    $config = $this->getGameConfig();
    $roundPerTurn = $config['modules']['turn_system']['round_per_turn'] ?? false;

    if ($roundPerTurn) {
        // Modo round-per-turn: avanza ronda en cada turno
        $turnInfo = $roundManager->nextTurnWithRoundAdvance();
    } else {
        // Modo normal: avanza ronda cuando se completa ciclo
        $turnInfo = $roundManager->nextTurn();
    }

    // Paso 3: Rotar roles automáticamente (si aplica)
    if ($shouldRotate) {
        $this->autoRotateRoles($match, $roundManager);
    }

    // Paso 4: Guardar cambios
    $this->saveRoundManager($match, $roundManager);

    return $turnInfo;
}
```

**3. RoundManager provee ambos métodos** (`app/Services/Modules/RoundSystem/RoundManager.php`):
```php
// Modo normal: avanza ronda cuando se completa un ciclo
public function nextTurn(): array
{
    $turnInfo = $this->turnManager->nextTurn();

    if ($this->turnManager->isCycleComplete()) {
        $this->currentRound++;
        $this->roundJustCompleted = true;
        $this->clearTemporaryEliminations();
    }

    return $turnInfo;
}

// Modo round-per-turn: avanza ronda SIEMPRE
public function nextTurnWithRoundAdvance(): array
{
    $turnInfo = $this->turnManager->nextTurn();

    // En round-per-turn, cada turno = nueva ronda
    $this->currentRound++;
    $this->roundJustCompleted = true;
    $this->clearTemporaryEliminations();

    return $turnInfo;
}
```

**4. PictionaryEngine solo llama a parent** (`games/pictionary/PictionaryEngine.php`):
```php
protected function nextTurn(GameMatch $match): array
{
    // ✅ BaseGameEngine maneja el modo automáticamente
    $turnInfo = parent::nextTurn($match);

    // Lógica específica de Pictionary
    $match->refresh();
    $gameState = $match->game_state;
    $gameState['current_word'] = $this->selectRandomWord($match, 'random');
    $gameState['current_drawer_id'] = $turnInfo['player_id'];

    $match->game_state = $gameState;
    $match->save();

    return $turnInfo;
}
```

### Ventajas de este Diseño

✅ **Centralización**: La lógica de modos está en UN solo lugar (BaseGameEngine)
✅ **Reutilización**: Todos los juegos heredan los modos automáticamente
✅ **Mantenibilidad**: Agregar un nuevo modo no requiere modificar cada juego
✅ **Consistencia**: Los módulos siempre son la fuente de verdad
✅ **Configuración**: Los juegos solo necesitan ajustar `config.json`

### Agregar un Nuevo Modo

Si necesitas agregar un nuevo modo de juego:

1. **Agregar método al módulo apropiado** (ej: `RoundManager::nextTurnWithCustomMode()`)
2. **Detectar config en BaseGameEngine** (leer del `config.json`)
3. **Llamar al método apropiado** (basado en la configuración)
4. **Configurar el juego** (activar en `config.json`)

**NO** implementes modos en juegos individuales.

## Checklist de Implementación

Al crear o modificar un GameEngine:

- [ ] ¿Usas `getRoundManager()` / `getRoleManager()` en lugar de leer `game_state` directamente?
- [ ] ¿Guardas módulos con `saveRoundManager()` / `saveRoleManager()` después de modificarlos?
- [ ] ¿Llamas a `parent::nextTurn()` para avanzar turnos en lugar de implementarlo tú mismo?
- [ ] ¿Llamas a `$match->refresh()` después de `parent::nextTurn()` antes de leer `game_state`?
- [ ] ¿Solo modificas `game_state` para datos ESPECÍFICOS del juego (no para datos de módulos)?

## Verificación

Para verificar que un GameEngine usa módulos correctamente:

1. **Estructura completa**: El `game_state` debe tener TODOS los campos de los módulos
   ```php
   $this->assertArrayHasKey('current_round', $gameState['round_system']);
   $this->assertArrayHasKey('total_rounds', $gameState['round_system']);
   $this->assertArrayHasKey('permanently_eliminated', $gameState['round_system']);
   $this->assertArrayHasKey('temporarily_eliminated', $gameState['round_system']);
   ```

2. **No duplicación**: Una acción debe avanzar la ronda exactamente 1 vez, no 2
   ```php
   $before = $match->game_state['round_system']['current_round'];
   // ... ejecutar acción ...
   $after = $match->game_state['round_system']['current_round'];
   $this->assertEquals(1, $after - $before); // Debe ser 1, no 2
   ```

3. **Sincronización**: Todos los módulos deben estar sincronizados
   ```php
   // Si RoundManager avanzó, TurnManager debe reflejarlo
   ```

## Resumen de Responsabilidades

### BaseGameEngine (Orquestador)
✅ **Implementar modos de juego** (round_per_turn, etc.)
✅ **Coordinar múltiples módulos** (ronda + turno + player + rol)
✅ **Leer configuración** de `config.json`
✅ **Proveer helpers** (`getRoundManager`, `saveRoundManager`, etc.)
✅ **Llamar métodos apropiados** según el modo configurado

❌ **NO** contiene lógica específica de un juego
❌ **NO** modifica `game_state` directamente

### GameEngine Específico (ej: PictionaryEngine)
✅ **Implementar lógica ESPECÍFICA** del juego (palabras, dibujo, etc.)
✅ **Llamar a `parent::nextTurn()`** para heredar modos
✅ **Modificar campos propios** en `game_state` (ej: `current_word`)

❌ **NO** implementa modos de juego (eso es de BaseGameEngine)
❌ **NO** modifica `game_state` de módulos directamente
❌ **NO** duplica lógica de BaseGameEngine

### Módulos (RoundManager, RoleManager, etc.)
✅ **Contener lógica de negocio** del módulo
✅ **Proveer métodos para diferentes modos** (ej: `nextTurn()` vs `nextTurnWithRoundAdvance()`)
✅ **Serializar/deserializar** con `toArray()` / `fromArray()`

❌ **NO** leen configuración (eso es de BaseGameEngine)
❌ **NO** coordinan con otros módulos directamente

### Tabla de Referencia Rápida

| Ubicación | Qué Hacer | Qué NO Hacer |
|-----------|-----------|--------------|
| **BaseGameEngine** | Implementar modos, coordinar módulos, leer config | Lógica específica de juegos |
| **GameEngine (Pictionary)** | Lógica del juego, llamar `parent::` | Implementar modos, modificar game_state de módulos |
| **Módulos** | Lógica de negocio, proveer métodos para modos | Leer config, coordinar con otros módulos |
| **Tests** | Usar API pública, observar `game_state` | Acceder a métodos `protected` |
| **Controllers** | Usar API pública (`processAction`, etc.) | Modificar `game_state` directamente |
| **Frontend** | Leer desde eventos WebSocket | Asumir estructura de `game_state` |

---

**Última actualización**: 2025-10-23
**Autor**: Claude Code + Daniel
