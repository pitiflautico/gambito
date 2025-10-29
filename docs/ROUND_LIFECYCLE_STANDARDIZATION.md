# Estandarización del Ciclo de Vida de Rondas

**Fecha:** 2025-10-29
**Contexto:** Análisis de qué código de Mentiroso debe moverse a BaseGameEngine

---

## 🎯 Objetivo

Analizar qué partes de la gestión de rondas en Mentiroso son **genéricas** y deberían estar en `BaseGameEngine` vs qué es **específico** del juego.

---

## 📊 Análisis del Código Actual

### ✅ YA ESTÁ EN BASE (No duplicar)

| Funcionalidad | Ubicación | Descripción |
|--------------|-----------|-------------|
| **handleNewRound()** | `BaseGameEngine:414-523` | Orquesta inicio de ronda: avanza contador, resetea PlayerManager, llama hooks, emite evento |
| **completeRound()** | `BaseGameEngine:828-868` | Orquesta fin de ronda: delega a RoundManager, verifica si terminó juego |
| **PlayerManager reset** | `BaseGameEngine:476` | Auto-resetea locks y acciones entre rondas |
| **RoundManager.completeRound()** | `RoundManager` | Emite RoundEndedEvent, guarda historial, verifica game complete |
| **CancelPhaseManagerTimersOnRoundEnd** | `Listener` | Auto-cancela timers de PhaseManager al terminar ronda |

### 🔴 ESPECÍFICO DE MENTIROSO (No mover)

| Código | Línea | Razón |
|--------|-------|-------|
| `loadNextStatement()` | 790-804 | Carga frases desde `statements.json` - específico de Mentiroso |
| `rotateOrador()` | 848-867 | Rotación de oradores - específico de Mentiroso |
| `assignRoles()` | 883-933 | Asigna roles orador/votante - específico de Mentiroso |
| `processVote()` | 329-434 | Lógica de votación - específico de Mentiroso |
| `getRoundResults()` | 505-619 | Calcula resultados con scoring de Mentiroso |

### 🟡 CANDIDATOS PARA MOVER A BASE

#### 1. **Defensive Check en `onRoundStarting()`**

**Código actual (Mentiroso:240-250):**
```php
// 🛡️ DEFENSIVE CHECK: Solo preparar ronda si NO hay statement actual
$gameState = $match->game_state;
if (isset($gameState['current_statement']) && $gameState['current_statement'] !== null) {
    Log::warning("[Mentiroso] Round already started, skipping onRoundStarting");
    return; // Ya se preparó esta ronda, no hacer nada
}
```

**Problema:**
- Este check previene doble-ejecución de `onRoundStarting()`
- Pero es **específico** a `current_statement` (campo de Mentiroso)

**Propuesta:** ❌ **NO MOVER A BASE**

**Razón:**
- Cada juego tiene diferentes campos para detectar "ronda ya iniciada"
  - Mentiroso: `current_statement`
  - Trivia: `current_question`
  - Pictionary: `current_word`
  - UNO: `current_card`

**Solución mejor:**
- Agregar método helper en `BaseGameEngine`:

```php
// BaseGameEngine
protected function isRoundAlreadyStarted(GameMatch $match, string $field): bool
{
    $gameState = $match->game_state;
    return isset($gameState[$field]) && $gameState[$field] !== null;
}
```

- Usar en cada juego:

```php
// MentirosoEngine::onRoundStarting()
if ($this->isRoundAlreadyStarted($match, 'current_statement')) {
    return;
}

// TriviaEngine::onRoundStarting()
if ($this->isRoundAlreadyStarted($match, 'current_question')) {
    return;
}
```

#### 2. **Limpiar estado de ronda en `endCurrentRound()`**

**Código actual (Mentiroso:472-481):**
```php
// ✅ IMPORTANTE: Limpiar current_statement ANTES de completeRound()
// Esto permite que onRoundStarting() de la siguiente ronda cargue nueva frase
$gameState = $match->game_state;
$gameState['current_statement'] = null;
$match->game_state = $gameState;
$match->save();
```

**Problema:**
- Necesario para que defensive check funcione
- Cada juego tiene su campo único

**Propuesta:** ✅ **SÍ MOVER A BASE (con hook)**

**Implementación:**

```php
// BaseGameEngine
protected function completeRound(GameMatch $match, array $results = []): void
{
    // 1. Llamar hook para que juego limpie su estado
    $this->onBeforeRoundComplete($match);

    // 2. Delegar a RoundManager
    $roundManager->completeRound($match, $results, $scores);

    // ... resto
}

/**
 * Hook: Ejecutado ANTES de completar la ronda.
 *
 * Usar para limpiar estado específico del juego que debe resetearse entre rondas.
 * Ejemplo: current_question, current_statement, current_word, etc.
 */
protected function onBeforeRoundComplete(GameMatch $match): void
{
    // Default: no hacer nada
    // Los juegos sobrescriben si necesitan limpiar estado
}
```

- Cada juego implementa:

```php
// MentirosoEngine
protected function onBeforeRoundComplete(GameMatch $match): void
{
    $gameState = $match->game_state;
    $gameState['current_statement'] = null;
    $match->game_state = $gameState;
    $match->save();
}

// TriviaEngine
protected function onBeforeRoundComplete(GameMatch $match): void
{
    $gameState = $match->game_state;
    $gameState['current_question'] = null;
    $match->game_state = $gameState;
    $match->save();
}
```

#### 3. **Lock de concurrencia en `endCurrentRound()`**

**Código actual (Mentiroso:460-490):**
```php
$lockKey = "game:match:{$match->id}:end-round";

\Illuminate\Support\Facades\Cache::lock($lockKey, 5)->block(3, function() use ($match) {
    $match->refresh();
    $results = $this->getRoundResults($match);
    $this->completeRound($match, $results);
});
```

**Problema:**
- Todos los juegos pueden tener race conditions al terminar ronda
- Mentiroso lo necesita porque múltiples jugadores pueden votar simultáneamente
- Trivia/Pictionary también pueden tenerlo

**Propuesta:** ✅ **SÍ MOVER A BASE**

**Implementación:**

```php
// BaseGameEngine
protected function endCurrentRoundWithLock(GameMatch $match): void
{
    $lockKey = "game:match:{$match->id}:end-round";

    \Illuminate\Support\Facades\Cache::lock($lockKey, 5)->block(3, function() use ($match) {
        // CRÍTICO: Refrescar match para obtener versión más reciente
        $match->refresh();

        Log::info("[{$this->getGameSlug()}] Ending current round (locked)", [
            'match_id' => $match->id
        ]);

        // Llamar hook para limpiar estado específico del juego
        $this->onBeforeRoundComplete($match);

        // Obtener resultados
        $results = $this->getRoundResults($match);

        // Completar ronda
        $this->completeRound($match, $results);

        Log::info("[{$this->getGameSlug()}] Round completed (locked)", [
            'match_id' => $match->id,
            'results' => $results
        ]);
    });
}
```

- Juegos que necesitan lock usan `endCurrentRoundWithLock()`:

```php
// MentirosoEngine
public function endCurrentRound(GameMatch $match): void
{
    // Usar versión con lock (múltiples votantes simultáneos)
    $this->endCurrentRoundWithLock($match);
}

// TriviaEngine
public function endCurrentRound(GameMatch $match): void
{
    // No necesita lock (solo 1 jugador responde a la vez)
    // O podría usar lock por seguridad
    $this->endCurrentRoundWithLock($match);
}
```

**Alternativa:** Hacer que `endCurrentRound()` BASE siempre use lock:

```php
// BaseGameEngine
protected function endCurrentRound(GameMatch $match): void
{
    $lockKey = "game:match:{$match->id}:end-round";

    \Illuminate\Support\Facades\Cache::lock($lockKey, 5)->block(3, function() use ($match) {
        $match->refresh();

        // Hook para limpiar estado
        $this->onBeforeRoundComplete($match);

        // Obtener resultados (abstract - cada juego implementa)
        $results = $this->getRoundResults($match);

        // Completar ronda (base)
        $this->completeRound($match, $results);
    });
}
```

**Ventaja:** Todos los juegos protegidos contra race conditions por defecto.

#### 4. **Crear PhaseManager en `onRoundStarting()`**

**Código actual (Mentiroso:270-302):**
```php
// 4. Iniciar PhaseManager y fase de preparación
$phaseManager = $this->createPhaseManager($match);
$this->savePhaseManager($match, $phaseManager);

$gameState = $match->game_state;
$gameState['current_phase'] = 'preparation';
$match->game_state = $gameState;
$match->save();

// Iniciar timer de la primera fase
$phaseManager->startTurnTimer();
$this->savePhaseManager($match, $phaseManager);
```

**Propuesta:** ❌ **NO MOVER A BASE**

**Razón:**
- Solo juegos con fases necesitan esto
- Trivia, UNO, etc. no usan PhaseManager
- Ya está bien encapsulado en métodos específicos del juego

---

## 🏗️ Propuesta de Refactoring

### Cambios en BaseGameEngine

#### 1. Hacer `endCurrentRound()` protegido con lock por defecto

```php
// app/Contracts/BaseGameEngine.php

/**
 * Finalizar la ronda actual.
 *
 * Método BASE que todos los juegos heredan automáticamente.
 * Incluye protección contra race conditions mediante lock.
 *
 * Flujo:
 * 1. Obtener lock (prevenir ejecuciones simultáneas)
 * 2. Refresh match (obtener última versión de BD)
 * 3. Llamar onBeforeRoundComplete() (hook para juego)
 * 4. Obtener resultados vía getRoundResults() (abstract)
 * 5. Completar ronda vía completeRound()
 *
 * OVERRIDE: Los juegos pueden sobrescribir si necesitan lógica especial,
 * pero deben llamar a parent::endCurrentRound() para mantener el lock.
 */
protected function endCurrentRound(GameMatch $match): void
{
    $lockKey = "game:match:{$match->id}:end-round";

    \Illuminate\Support\Facades\Cache::lock($lockKey, 5)->block(3, function() use ($match) {
        // CRÍTICO: Refrescar match para obtener versión más reciente
        $match->refresh();

        Log::info("[{$this->getGameSlug()}] Ending current round", [
            'match_id' => $match->id
        ]);

        // Hook: Limpiar estado específico del juego
        $this->onBeforeRoundComplete($match);

        // Obtener resultados (cada juego implementa getRoundResults)
        $results = $this->getRoundResults($match);

        // Completar ronda (base)
        $this->completeRound($match, $results);

        Log::info("[{$this->getGameSlug()}] Round completed", [
            'match_id' => $match->id,
            'results' => $results
        ]);
    });
}
```

#### 2. Agregar hook `onBeforeRoundComplete()`

```php
/**
 * Hook: Ejecutado ANTES de completar la ronda (dentro del lock).
 *
 * Usar para limpiar estado específico del juego que debe resetearse entre rondas.
 * Esto permite que onRoundStarting() detecte que es una nueva ronda.
 *
 * Ejemplos de uso:
 * - Limpiar current_question (Trivia)
 * - Limpiar current_statement (Mentiroso)
 * - Limpiar current_word (Pictionary)
 * - Limpiar current_card (UNO)
 *
 * IMPORTANTE: Este hook se ejecuta dentro del lock de endCurrentRound(),
 * por lo que es seguro modificar game_state sin race conditions.
 *
 * @param GameMatch $match
 */
protected function onBeforeRoundComplete(GameMatch $match): void
{
    // Default: no hacer nada
    // Los juegos sobrescriben si necesitan limpiar estado
}
```

#### 3. Agregar helper `isRoundAlreadyStarted()`

```php
/**
 * Helper: Verificar si una ronda ya fue iniciada.
 *
 * Útil para defensive checks en onRoundStarting() que previenen
 * doble-ejecución del hook.
 *
 * @param GameMatch $match
 * @param string $field Campo del game_state que indica ronda iniciada
 * @return bool
 */
protected function isRoundAlreadyStarted(GameMatch $match, string $field): bool
{
    $gameState = $match->game_state;
    return isset($gameState[$field]) && $gameState[$field] !== null;
}
```

### Cambios en Juegos Específicos

#### MentirosoEngine

```php
/**
 * Hook: Limpiar estado antes de completar ronda
 */
protected function onBeforeRoundComplete(GameMatch $match): void
{
    // Limpiar statement actual para permitir cargar siguiente
    $gameState = $match->game_state;
    $gameState['current_statement'] = null;
    $match->game_state = $gameState;
    $match->save();

    Log::info("[Mentiroso] Current statement cleared for next round", [
        'match_id' => $match->id
    ]);
}

/**
 * Hook: Preparar datos para nueva ronda
 */
protected function onRoundStarting(GameMatch $match): void
{
    $roundManager = $this->getRoundManager($match);
    $currentRound = $roundManager->getCurrentRound();

    // 🛡️ DEFENSIVE CHECK: Prevenir doble-ejecución
    if ($this->isRoundAlreadyStarted($match, 'current_statement')) {
        Log::warning("[Mentiroso] Round already started, skipping onRoundStarting", [
            'match_id' => $match->id,
            'current_round' => $currentRound
        ]);
        return;
    }

    // Rotar orador (excepto primera ronda)
    if ($currentRound > 1) {
        $this->rotateOrador($match);
    }

    // Cargar siguiente frase
    $statement = $this->loadNextStatement($match);

    // Asignar roles
    $this->assignRoles($match);

    // Iniciar PhaseManager
    $phaseManager = $this->createPhaseManager($match);
    $this->savePhaseManager($match, $phaseManager);
    $phaseManager->startTurnTimer();
    $this->savePhaseManager($match, $phaseManager);

    // Emitir evento privado al orador
    $this->emitStatementToOrador($match, $statement);
}

/**
 * Override: Usar implementación base con lock
 * (Ya no necesita sobrescribir si base ya tiene lock)
 */
// public function endCurrentRound(GameMatch $match): void
// {
//     parent::endCurrentRound($match); // Usa base con lock
// }
```

#### TriviaEngine

```php
/**
 * Hook: Limpiar estado antes de completar ronda
 */
protected function onBeforeRoundComplete(GameMatch $match): void
{
    // Limpiar pregunta actual
    $gameState = $match->game_state;
    $gameState['current_question'] = null;
    $match->game_state = $gameState;
    $match->save();
}

/**
 * Hook: Preparar datos para nueva ronda
 */
protected function onRoundStarting(GameMatch $match): void
{
    // Defensive check
    if ($this->isRoundAlreadyStarted($match, 'current_question')) {
        return;
    }

    // Cargar siguiente pregunta
    $question = $this->loadNextQuestion($match);

    // Guardar en estado
    $gameState = $match->game_state;
    $gameState['current_question'] = $question;
    $match->game_state = $gameState;
    $match->save();
}
```

---

## 📝 Resumen de Cambios

### ✅ MOVER A BASE

1. **`endCurrentRound()` con lock** - Protección contra race conditions por defecto
2. **Hook `onBeforeRoundComplete()`** - Permite juegos limpiar estado antes de completar
3. **Helper `isRoundAlreadyStarted()`** - Facilita defensive checks

### ❌ NO MOVER A BASE (mantener en juegos)

1. **Defensive check específico** - Cada juego usa su campo único
2. **Crear PhaseManager** - Solo juegos con fases lo necesitan
3. **Lógica de votación/respuestas** - Específico de cada juego
4. **Rotación de roles** - Específico de cada juego
5. **Carga de contenido** - Cada juego carga su propio contenido (preguntas, frases, palabras)

### 🟢 YA ESTÁ EN BASE (no tocar)

1. **`handleNewRound()`** - Orquesta inicio de ronda
2. **`completeRound()`** - Orquesta fin de ronda
3. **PlayerManager reset** - Auto-resetea locks
4. **CancelPhaseManagerTimersOnRoundEnd** - Auto-cancela timers

---

## 🎯 Beneficios del Refactoring

### Para BaseGameEngine

1. ✅ **Todos los juegos protegidos contra race conditions** - Lock por defecto
2. ✅ **Pattern consistente** - Todos los juegos limpian estado de la misma manera
3. ✅ **Menos código duplicado** - Helper `isRoundAlreadyStarted()` reutilizable
4. ✅ **Más fácil depurar** - Flujo estándar en base

### Para Juegos Específicos

1. ✅ **Menos boilerplate** - No necesitan reimplementar lock
2. ✅ **Más claro** - Hooks con nombres descriptivos
3. ✅ **Más seguro** - Lock garantizado
4. ✅ **Más fácil testear** - Menos lógica en cada juego

---

## 🚀 Plan de Implementación

### Fase 1: Agregar a BaseGameEngine (sin breaking changes)

```bash
# 1. Agregar helper isRoundAlreadyStarted() a BaseGameEngine
# 2. Agregar hook onBeforeRoundComplete() a BaseGameEngine (vacío por defecto)
# 3. Modificar endCurrentRound() en BaseGameEngine para:
#    - Agregar lock
#    - Llamar onBeforeRoundComplete()
#    - Mantener retrocompatibilidad
```

### Fase 2: Actualizar Mentiroso

```bash
# 1. Implementar onBeforeRoundComplete() en MentirosoEngine
# 2. Usar isRoundAlreadyStarted() en onRoundStarting()
# 3. Eliminar override de endCurrentRound() (usar base)
# 4. Probar que todo funciona
```

### Fase 3: Actualizar Trivia y Pictionary

```bash
# 1. Implementar onBeforeRoundComplete() en TriviaEngine
# 2. Implementar onBeforeRoundComplete() en PictionaryEngine
# 3. Usar isRoundAlreadyStarted() en ambos
# 4. Probar que todo funciona
```

### Fase 4: Documentar

```bash
# 1. Actualizar docs/GAME_ENGINE_LIFECYCLE.md
# 2. Agregar ejemplos a docs/
# 3. Actualizar /create-game con el nuevo pattern
```

---

## 📚 Frontend (BaseGameClient.js)

### ¿Qué mover a base?

**Análisis:**

- Mentiroso usa defensive check en `handlePhaseChanged()` (ignorar eventos durante resultados)
- Esto es específico de juegos con fases dentro de rondas
- Trivia/UNO no tienen fases → No lo necesitan

**Conclusión:** ❌ **NO MOVER A BASE**

**Razón:**
- No todos los juegos tienen fases
- El pattern "ignorar eventos irrelevantes para estado actual" ya está documentado en PHASE_SYSTEM_LEARNINGS.md
- Cada juego implementa según sus necesidades

**Ejemplo documentado:**

```javascript
// Pattern para juegos con fases
async handlePhaseChanged(event) {
    // Defensive check basado en estado actual
    if (this.isInState('showing_results')) {
        console.log('Ignoring PhaseChangedEvent - showing results');
        return;
    }

    // Procesar evento
    this.updatePhase(event.new_phase);
}
```

---

## ✅ Conclusión

### Lo que DEBE moverse a BASE:

1. ✅ **`endCurrentRound()` con lock** - Beneficia a todos los juegos
2. ✅ **Hook `onBeforeRoundComplete()`** - Pattern consistente
3. ✅ **Helper `isRoundAlreadyStarted()`** - Reutilizable

### Lo que NO debe moverse:

1. ❌ **Defensive checks específicos** - Cada juego tiene su campo
2. ❌ **PhaseManager creation** - Solo juegos con fases
3. ❌ **Lógica de gameplay** - Específico de cada juego
4. ❌ **Frontend defensive checks** - No todos tienen fases

### Impacto:

- **Positivo:** Menos código duplicado, más seguridad por defecto
- **Riesgo:** Bajo - Cambios son aditivos, no rompen juegos existentes
- **Esfuerzo:** Medio - 3-4 horas de refactoring + testing
