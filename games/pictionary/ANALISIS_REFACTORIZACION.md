# 🔍 Análisis Completo: Refactorización de Pictionary

## 📊 Estado Actual vs Convenciones Modernas

### ✅ Lo que está BIEN
1. Usa `onRoundStarting()` correctamente (línea 780)
2. Usa `PlayerManager` unificado (no PlayerStateManager obsoleto)
3. Extiende `BaseGameEngine` correctamente
4. Implementa `getRoundResults()` correctamente

### ❌ Problemas Críticos Identificados

#### 1. CONFIG.JSON - Falta `phase_system` y `event_config`
**Problema**:
- ❌ No tiene `phase_system.phases[]` definido
- ❌ No tiene `event_config.events{}` con nombres de eventos
- ❌ No sigue el patrón de Mockup/Trivia con eventos custom de fase

**Comparación**:
```json
// ❌ ACTUAL (Pictionary):
{
  "modules": {
    "phase_system": {
      "enabled": true  // Pero NO tiene phases[]
    }
  }
  // Sin event_config
}

// ✅ CORRECTO (Mockup/Trivia):
{
  "modules": {
    "phase_system": {
      "enabled": true,
      "phases": [
        {
          "name": "preparation",
          "duration": 10,
          "on_start": "App\\Events\\Pictionary\\PreparationStartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handlePreparationEnded"
        },
        {
          "name": "drawing",
          "duration": 60,
          "on_start": "App\\Events\\Pictionary\\DrawingStartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handleDrawingEnded"
        },
        {
          "name": "voting",
          "duration": 15,
          "on_start": "App\\Events\\Pictionary\\VotingStartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handleVotingEnded"
        }
      ]
    }
  },
  "event_config": {
    "channel": "room.{roomCode}",
    "events": {
      "PreparationStartedEvent": {
        "name": ".pictionary.preparation.started",
        "handler": "handlePreparationStarted"
      },
      "DrawingStartedEvent": {
        "name": ".pictionary.drawing.started",
        "handler": "handleDrawingStarted"
      },
      "VotingStartedEvent": {
        "name": ".pictionary.voting.started",
        "handler": "handleVotingStarted"
      }
    }
  }
}
```

#### 2. CAPABILITIES.JSON - Nombres de eventos incorrectos
**Problema**:
- ❌ Eventos genéricos sin punto inicial: `"game.round.started"` 
- ✅ Debería tener punto: `".game.round.started"` (pero esto es para config.json)
- ❌ Eventos custom sin estructura clara

**Comparación**:
```json
// ❌ ACTUAL:
"RoundStartedEvent": {
  "name": "game.round.started",  // Sin punto
  ...
}

// ✅ CORRECTO (para capabilities.json - SIN punto inicial):
"PreparationStartedEvent": {
  "name": "pictionary.preparation.started",  // Sin punto (correcto para capabilities)
  ...
}

// ✅ CORRECTO (para config.json - CON punto inicial):
"PreparationStartedEvent": {
  "name": ".pictionary.preparation.started",  // CON punto (correcto para config)
  ...
}
```

#### 3. ENGINE - Usa patrón obsoleto `onRoundStarted()` con PhaseChangedEvent
**Problema**:
- ❌ Línea 177: Implementa `onRoundStarted()` manualmente emitiendo `PhaseChangedEvent`
- ✅ Debería usar eventos custom de fase como Mockup/Trivia
- ❌ No tiene eventos `PreparationStartedEvent`, `DrawingStartedEvent`, etc.

**Código actual (líneas 177-207)**:
```php
// ❌ PATRÓN OBSOLETO:
protected function onRoundStarted(...): void
{
    // Emite PhaseChangedEvent manualmente
    event(new PhaseChangedEvent(...));
}
```

**Patrón correcto**:
- Eventos custom de fase se emiten automáticamente por `PhaseManager.startPhase()`
- No necesitas `onRoundStarted()` para emitir eventos
- Los eventos se emiten basados en `config.json` → `phase_system.phases[].on_start`

#### 4. ENGINE - Falta método `startNewRound()`
**Problema**:
- ❌ No tiene `startNewRound()` (solo tiene `onRoundStarting()`)
- ✅ Debería tener ambos: `onRoundStarting()` llama a `startNewRound()`
- ❌ Toda la lógica está en `onRoundStarting()` cuando debería estar en `startNewRound()`

**Comparación**:
```php
// ❌ ACTUAL:
protected function onRoundStarting(GameMatch $match): void
{
    // Toda la lógica aquí
    $this->rotateDrawer($match);
    $this->loadNextWord($match);
    // ...
}

// ✅ CORRECTO (patrón Trivia/Mockup):
protected function onRoundStarting(GameMatch $match): void
{
    // Solo llama a startNewRound()
    $this->startNewRound($match);
}

protected function startNewRound(GameMatch $match): void
{
    // NOTA: reset() ya se llama automáticamente en handleNewRound()
    // Solo lógica específica del juego
    $this->rotateDrawer($match);
    $this->loadNextWord($match);
    $this->assignRoles($match);
    // Establecer UI si es necesario
    $match->save();
}
```

#### 5. EVENTOS - No tiene clases de eventos custom de fase
**Problema**:
- ❌ No existen `PreparationStartedEvent.php`, `DrawingStartedEvent.php`, `VotingStartedEvent.php`
- ❌ No siguen el patrón de `MockupEngine` o `TriviaEngine`
- ✅ Debería crear eventos custom para cada fase

**Ejemplo necesario**:
```php
// app/Events/Pictionary/DrawingStartedEvent.php
class DrawingStartedEvent implements ShouldBroadcastNow
{
    // Mismo patrón que Phase1StartedEvent de Mockup/Trivia
}
```

#### 6. CLIENT - Posiblemente usa handlers obsoletos
**Problema**:
- Necesito revisar si sigue el patrón de `setupEventManager()` correctamente
- Verificar que use `customHandlers` correctamente

#### 7. CONFIG.JSON - Falta `roles_system.roles` con estructura correcta
**Problema actual**:
```json
"roles_system": {
  "enabled": true,
  "roles": ["drawer", "guesser", "viewer"]  // ❌ Array simple
}
```

**Correcto**:
```json
"roles_system": {
  "enabled": true,
  "roles": [
    {
      "name": "drawer",
      "count": 1,
      "description": "El jugador que dibuja",
      "rotate_on_round_start": true
    },
    {
      "name": "guesser",
      "count": -1,
      "description": "Los jugadores que adivinan",
      "rotate_on_round_start": false
    }
  ]
}
```

---

## 📋 PLAN DE REFACTORIZACIÓN POR FASES

### FASE 1: Actualizar config.json
**Tareas**:
- [ ] Agregar `phase_system.phases[]` con 3 fases:
  - `preparation`: Seleccionar palabra, asignar roles (10s)
  - `drawing`: Dibujar y adivinar (60s)
  - `voting`: (opcional, si se implementa)
- [ ] Agregar `event_config.events{}` con eventos custom:
  - `PreparationStartedEvent`
  - `DrawingStartedEvent`
  - (opcional: `VotingStartedEvent`)
- [ ] Actualizar `roles_system.roles[]` a estructura de objetos
- [ ] Agregar `timing.round_ended` con auto_next
- [ ] Validar JSON

**Referencia**: `games/mockup/config.json`

---

### FASE 2: Actualizar capabilities.json
**Tareas**:
- [ ] Agregar eventos custom en `event_config.events{}`:
  - `PreparationStartedEvent` → `name: "pictionary.preparation.started"` (SIN punto)
  - `DrawingStartedEvent` → `name: "pictionary.drawing.started"` (SIN punto)
- [ ] Asegurar que handlers coincidan con config.json
- [ ] Validar JSON

**Referencia**: `games/mockup/capabilities.json`, `games/trivia/capabilities.json`

---

### FASE 3: Crear Eventos Custom de Fase
**Tareas**:
- [ ] Crear `app/Events/Pictionary/PreparationStartedEvent.php`
- [ ] Crear `app/Events/Pictionary/DrawingStartedEvent.php`
- [ ] Cada evento debe:
  - Extender patrón de `MockupEngine` o `TriviaEngine`
  - `broadcastOn()` → `PresenceChannel`
  - `broadcastAs()` → `pictionary.{fase}.started` (SIN punto)
  - `broadcastWith()` → incluir `duration`, `timer_id`, `server_time`, `event_class`
- [ ] Validar sintaxis PHP

**Referencia**: 
- `app/Events/Mockup/Phase1StartedEvent.php`
- `app/Events/Trivia/Phase1StartedEvent.php`

---

### FASE 4: Refactorizar Engine - Métodos Base y Eliminar Código Deprecated
**Tareas**:
- [ ] Crear método `startNewRound()`:
  - Mover lógica de `onRoundStarting()` a `startNewRound()`
  - NO llamar `reset()` (ya se hace automáticamente)
  - Solo lógica específica: rotar drawer, cargar palabra, asignar roles
- [ ] Simplificar `onRoundStarting()`:
  - Solo llamar a `startNewRound()`
- [ ] **ELIMINAR métodos obsoletos**:
  - ❌ `getRoundStartTiming()` (líneas 166-169) - Patrón obsoleto
  - ❌ `onRoundStarted()` (líneas 177-207) - Emite PhaseChangedEvent manualmente, obsoleto
- [ ] **ELIMINAR llamadas deprecated**:
  - ❌ `$this->cachePlayersInState($match)` (línea 95) - No es necesario
- [ ] Limpiar comentarios obsoletos (líneas 1302-1312 sobre métodos heredados)

**Referencia**: 
- `games/trivia/TriviaEngine.php::startNewRound()`
- `games/trivia/TriviaEngine.php::onRoundStarting()`

---

### FASE 5: Refactorizar Engine - Callbacks de Fase
**Tareas**:
- [ ] Implementar `handlePreparationEnded()`:
  - Obtener PhaseManager
  - `$phaseManager->setMatch($match)` ⚠️ CRÍTICO
  - `$phaseManager->nextPhase()`
  - Si `cycle_completed` → `endCurrentRound()`
  - Si no → emitir `PhaseChangedEvent`
- [ ] Implementar `handleDrawingEnded()`:
  - Similar a `handlePreparationEnded()`
- [ ] Validar que todos los callbacks usen `setMatch()` antes de `nextPhase()`

**Referencia**: 
- `games/mockup/MockupEngine.php::handlePhase1Ended()`
- `games/mockup/MockupEngine.php::handlePhase2Ended()`

---

### FASE 6: Actualizar Cliente JS
**Tareas**:
- [ ] Verificar `setupEventManager()` sigue patrón correcto
- [ ] Agregar handlers para eventos custom:
  - `handlePreparationStarted()`
  - `handleDrawingStarted()`
- [ ] Verificar que use `customHandlers` correctamente
- [ ] Validar que no haya handlers obsoletos

**Referencia**: 
- `games/mockup/js/MockupGameClient.js::setupEventManager()`
- `games/trivia/js/TriviaClient.js` (si existe)

---

### FASE 7: Eliminar Código Deprecated
**Tareas**:
- [ ] Eliminar método `getRoundStartTiming()` (líneas 166-169)
- [ ] Eliminar método `onRoundStarted()` completo (líneas 177-207)
- [ ] Eliminar llamada `cachePlayersInState()` (línea 95)
- [ ] Eliminar comentarios obsoletos (líneas 1302-1312)
- [ ] Resolver/eliminar TODOs si aplica:
  - `processGuess()` línea 257 - Verificar si TODO sigue siendo válido
  - `PictionaryGameClient.js` línea 33 - Verificar estado de inicialización canvas
  - `PictionaryScoreCalculator.php` línea 69 - Decidir implementación o eliminar
- [ ] Validar que no queden referencias a métodos eliminados

---

### FASE 8: Testing y Validación
**Tareas**:
- [ ] Validar sintaxis PHP: `php -l app/Events/Pictionary/*.php`
- [ ] Validar sintaxis PHP Engine: `php -l games/pictionary/PictionaryEngine.php`
- [ ] Validar sintaxis JS: verificar compilación `npm run build`
- [ ] Validar JSON: `config.json` y `capabilities.json`
- [ ] Testing manual:
  - Iniciar juego
  - Verificar eventos llegan al frontend
  - Verificar fases avanzan correctamente
  - Verificar roles se asignan
  - Verificar canvas funciona
  - Verificar que no hay errores de métodos deprecated

---

## 📊 Resumen de Cambios Necesarios

### Archivos a Crear:
1. `app/Events/Pictionary/PreparationStartedEvent.php`
2. `app/Events/Pictionary/DrawingStartedEvent.php`

### Archivos a Modificar:
1. `games/pictionary/config.json` - Agregar phases y event_config
2. `games/pictionary/capabilities.json` - Agregar eventos custom
3. `games/pictionary/PictionaryEngine.php` - Refactorizar métodos
4. `games/pictionary/js/PictionaryGameClient.js` - Agregar handlers

### Archivos a Eliminar/Simplificar:

**Código a ELIMINAR de PictionaryEngine.php**:
1. ❌ Método `getRoundStartTiming()` (líneas 166-169) - Patrón obsoleto
2. ❌ Método `onRoundStarted()` completo (líneas 177-207) - Emite PhaseChangedEvent manualmente
3. ❌ Llamada `$this->cachePlayersInState($match)` (línea 95) - Método deprecated
4. ❌ Comentarios obsoletos sobre métodos heredados (líneas 1302-1312)

**Métodos a simplificar**:
- `onRoundStarting()` - Mover lógica a `startNewRound()`

---

## 🎯 Orden de Ejecución

**Seguir este orden estricto**:
1. FASE 1 → Config.json (base para todo)
2. FASE 2 → Capabilities.json (depende de config.json)
3. FASE 3 → Eventos PHP (depende de config.json)
4. FASE 4 → Engine base + Eliminar código deprecated (depende de eventos)
5. FASE 5 → Engine callbacks (depende de FASE 4)
6. FASE 6 → Cliente JS (depende de eventos)
7. FASE 7 → Eliminar código deprecated restante (limpieza final)
8. FASE 8 → Testing y validación (valida todo)

---

## 🗑️ Código y Archivos Deprecated a ELIMINAR

### En PictionaryEngine.php:

**1. Método `getRoundStartTiming()` (líneas 166-169)**:
```php
// ❌ ELIMINAR COMPLETAMENTE
protected function getRoundStartTiming(GameMatch $match): ?array
{
    return null; // NO timing en RoundStartedEvent
}
```
**Razón**: Patrón obsoleto. El timing ahora se maneja automáticamente por eventos de fase.

---

**2. Método `onRoundStarted()` (líneas 177-207)**:
```php
// ❌ ELIMINAR COMPLETAMENTE
protected function onRoundStarted(GameMatch $match, int $currentRound, int $totalRounds): void
{
    // Emite PhaseChangedEvent manualmente
    event(new PhaseChangedEvent(...));
}
```
**Razón**: Patrón obsoleto. Los eventos de fase se emiten automáticamente por `PhaseManager.startPhase()` basado en `config.json`.

---

**3. Llamada `cachePlayersInState()` (línea 95)**:
```php
// ❌ ELIMINAR ESTA LÍNEA
$this->cachePlayersInState($match);
```
**Razón**: Método deprecated. Ya no es necesario cachear jugadores manualmente.

---

**4. Comentarios obsoletos (líneas 1302-1312)**:
```php
// ❌ ELIMINAR SECCIÓN COMPLETA
// ========================================================================
// MÉTODOS HEREDADOS (NO REIMPLEMENTAR)
// ========================================================================
//
// Los siguientes métodos se heredan de BaseGameEngine y NO deben sobrescribirse:
// - handlePlayerDisconnect/Reconnect(): OBSOLETOS, usar hooks beforePlayerDisconnectedPause() y afterPlayerReconnected()
```
**Razón**: Comentarios confusos y referencias a métodos obsoletos. Limpiar.

---

### En otros archivos:

**TODO Comments a resolver**:
- `processGuess()` línea 257: "TODO: Implementar lógica de validación" - Ya está implementado, eliminar comentario
- `PictionaryGameClient.js` línea 33: "TODO: Inicializar canvas" - Verificar si se hace, eliminar si está hecho
- `PictionaryScoreCalculator.php` línea 69: "TODO: Podría escalar según cuántos jugadores adivinaron" - Decidir si implementar o eliminar

---

## ⚠️ Errores Críticos a Evitar

1. **NO olvidar `setMatch()` en callbacks** - Causa eventos que no se emiten
2. **NO duplicar `reset()` en `startNewRound()`** - Ya se llama automáticamente
3. **NO usar punto inicial en capabilities.json** - Solo en config.json
4. **NO olvidar registrar eventos en capabilities.json** - Sin esto, eventos no llegan al frontend
5. **NO dejar código deprecated** - Eliminar todo código obsoleto identificado arriba

---

## 📚 Referencias Clave

- `games/mockup/config.json` - Estructura completa de fases
- `games/mockup/MockupEngine.php` - Patrón correcto de callbacks
- `games/trivia/TriviaEngine.php` - Patrón `onRoundStarting()` → `startNewRound()`
- `docs/EVENTOS_Y_ERRORES_CRITICOS.md` - Convenciones de eventos
- `docs/ROLE_ROTATION_TYPES.md` - Sistema de roles

