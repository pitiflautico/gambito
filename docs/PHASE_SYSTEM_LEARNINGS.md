# Sistema de Fases - Aprendizajes y Mejoras Base

**Fecha:** 2025-10-29
**Contexto:** Fixes aplicados durante el desarrollo de Mentiroso (primer juego con múltiples fases por ronda)

---

## 🎯 Resumen Ejecutivo

Durante el desarrollo de **Mentiroso**, el primer juego con **múltiples fases por ronda** (preparation → persuasion → voting), descubrimos varios bugs y antipatrones en el sistema base de PhaseManager. Este documento detalla:

1. ✅ **Qué funcionaba mal** en la arquitectura base
2. ✅ **Qué arreglamos** específicamente para Mentiroso
3. ✅ **Qué debe aplicarse como funcionalidad BASE** para cualquier juego con fases

---

## 📋 Cambios Realizados (Cronológico)

### 1️⃣ **CancelPhaseManagerTimersOnRoundEnd Listener** (BASE - YA IMPLEMENTADO)

**Archivo:** `app/Listeners/CancelPhaseManagerTimersOnRoundEnd.php`

**Problema:**
- Cuando terminaba una ronda, los timers de PhaseManager seguían activos
- Esto causaba race conditions cuando empezaba la siguiente ronda
- El juego tenía que cancelar manualmente los timers (acoplamiento innecesario)

**Solución:**
- Creamos un **Event Listener global** que escucha `RoundEndedEvent`
- Automáticamente detecta si el juego usa PhaseManager (comprueba `game_state['phase_manager']`)
- Cancela todos los timers pendientes
- Resetea las fases a la primera fase para la siguiente ronda

**¿Es BASE?** ✅ **SÍ - YA ESTÁ EN BASE**

```php
// app/Listeners/CancelPhaseManagerTimersOnRoundEnd.php
class CancelPhaseManagerTimersOnRoundEnd
{
    public function handle(RoundEndedEvent $event): void
    {
        $match = $event->match;
        $gameState = $match->game_state;

        // ✅ Verificar si el juego usa PhaseManager
        if (!isset($gameState['phase_manager'])) {
            return; // Este juego no usa PhaseManager
        }

        // Reconstruir PhaseManager desde game_state
        $phaseManagerData = $gameState['phase_manager'];
        $phaseManager = PhaseManager::fromArray($phaseManagerData);

        // ✅ Cancelar todos los timers automáticamente
        $phaseManager->cancelAllTimers();

        // ✅ Resetear fases a la primera
        $phaseManagerData['current_turn_index'] = 0;
        $phaseManagerData['is_paused'] = false;
        $phaseManagerData['direction'] = 1;

        $gameState['phase_manager'] = $phaseManagerData;
        $match->game_state = $gameState;
        $match->save();
    }
}
```

**Beneficio:** Cualquier juego que use PhaseManager obtiene gestión automática de timers entre rondas.

---

### 2️⃣ **Eliminar Flag `_completing_round` (ANTIPATRÓN)**

**Archivos:** `games/mentiroso/MentirosoEngine.php`

**Problema:**
- Mentiroso usaba un flag `$this->_completing_round` para prevenir ejecuciones duplicadas de `endCurrentRound()`
- Este flag se ponía en `true` al empezar a completar la ronda
- Se limpiaba en `onRoundStarting()` DESPUÉS de una validación defensiva
- Si la validación retornaba early, el flag nunca se limpiaba → **bloqueaba futuras rondas**

**Ejemplo del antipatrón:**
```php
// ❌ ANTIPATRÓN - NO HACER ESTO
protected function endCurrentRound(Match $match): void
{
    if ($this->_completing_round) {
        return; // Evitar duplicados
    }

    $this->_completing_round = true; // ⚠️ ¿Cuándo se limpia?

    // ... lógica de completar ronda
}

protected function onRoundStarting(Match $match): void
{
    // Validación defensiva
    if (!$someCondition) {
        return; // ⚠️ El flag nunca se limpia!
    }

    $this->_completing_round = false; // Solo se ejecuta si no hay return early
}
```

**Solución:**
- Eliminamos completamente el flag `_completing_round`
- Usamos **concurrency locks** de Laravel Cache que se auto-limpian:

```php
// ✅ CORRECTO - Usar concurrency locks
protected function endCurrentRound(Match $match): void
{
    $lock = Cache::lock("complete_round:{$match->id}", 10);

    if (!$lock->get()) {
        Log::info("[Mentiroso] Another process is completing round, skipping");
        return; // Otro proceso ya está completando la ronda
    }

    try {
        // ... lógica de completar ronda
        $this->completeRound($match, $results, $scores);
    } finally {
        $lock->release(); // ✅ Siempre se libera
    }
}
```

**¿Es BASE?** ✅ **SÍ - DEBE SER PATRÓN ESTÁNDAR**

**Regla para todos los juegos:**
- **NUNCA usar flags booleanos** para prevenir ejecuciones duplicadas
- **SIEMPRE usar Cache::lock()** con try/finally
- Los locks se auto-limpian (tienen timeout automático)
- El bloque `finally` garantiza liberación incluso con excepciones

---

### 3️⃣ **Defensive Check en `handlePhaseChanged()` (FRONTEND)**

**Archivos:** `games/mentiroso/js/MentirosoGameClient.js`

**Problema:**
- Backend emitía `RoundEndedEvent` con delay de 10 segundos
- Frontend mostraba pantalla de resultados y empezaba countdown
- Backend inmediatamente iniciaba nueva ronda → emitía `PhaseChangedEvent` (preparation)
- Frontend procesaba `PhaseChangedEvent` → **ocultaba resultados prematuramente**
- Resultado: pantalla de resultados desaparecía en 1 segundo en vez de 10

**Flujo incorrecto:**
```
1. Backend: RoundEndedEvent (delay: 10s)
2. Frontend: Mostrar resultados + countdown 10s
3. Backend: PhaseChangedEvent (new_phase: 'preparation') ← ⚠️ Llega inmediatamente
4. Frontend handlePhaseChanged(): Actualizar UI → ❌ Oculta resultados
```

**Solución:**
```javascript
// games/mentiroso/js/MentirosoGameClient.js
async handlePhaseChanged(event) {
    const { new_phase, previous_phase, additional_data } = event;
    const phase = additional_data?.phase || new_phase;

    // 🛡️ DEFENSIVE CHECK: Si estamos mostrando resultados, IGNORAR PhaseChangedEvent
    // hasta que termine el countdown
    const resultsPhase = document.getElementById('results-phase');
    if (resultsPhase && !resultsPhase.classList.contains('hidden')) {
        console.log('[Mentiroso] ⏸️ Ignoring PhaseChangedEvent while showing results', {
            new_phase: phase,
            reason: 'waiting_for_round_countdown'
        });
        return; // ✅ No procesar eventos irrelevantes para el estado actual
    }

    // Continuar con el procesamiento normal
    this.updatePhase(phase);
    // ...
}
```

**¿Es BASE?** 🟡 **DEPENDE DEL JUEGO**

**Explicación:**
- Este patrón es específico para juegos con **fases dentro de rondas**
- No todos los juegos necesitan este check (ej: Trivia, UNO no tienen fases)
- **PERO el principio SÍ es base:**

**Principio Base:**
> **Los componentes deben ignorar eventos que no son relevantes para su estado actual**

**Ejemplos de aplicación:**
- Si estás en "waiting for players", ignora eventos de gameplay
- Si estás en "results", ignora eventos de nueva ronda
- Si estás en "finished", ignora eventos de ronda

**Regla general:**
```javascript
async handleSomeEvent(event) {
    // 1. Verificar estado actual
    const currentState = this.getCurrentState();

    // 2. ¿Es relevante este evento para mi estado?
    if (!this.isEventRelevantForState(event, currentState)) {
        console.log('Ignoring event - not relevant for current state');
        return;
    }

    // 3. Procesar evento
    this.processEvent(event);
}
```

---

### 4️⃣ **Logging Comprehensivo con Emojis** (DEBUGGING)

**Archivos:** `games/mentiroso/MentirosoEngine.php`

**Problema:**
- Difícil rastrear el flujo de ejecución cuando hay race conditions
- Logs mezclados de múltiples requests simultáneos
- No quedaba claro cuántas veces se emitía un evento

**Solución:**
Agregamos logging con **emojis distintivos** para rastrear:
- 🚀🚀🚀 = Emisión de eventos
- 🔵🔵🔵 = Entrada a método
- 🔴🔴🔴 = Salida de método

```php
// Ejemplo en advanceToNextPhase()
public function advanceToNextPhase(Match $match): void
{
    Log::info("🔵🔵🔵 [ENTRY] advanceToNextPhase", [
        'match_id' => $match->id,
        'location' => 'advanceToNextPhase'
    ]);

    // ... lógica ...

    Log::info("🚀🚀🚀 [EMIT] PhaseChangedEvent FROM advanceToNextPhase", [
        'match_id' => $match->id,
        'room_code' => $match->room->code,
        'new_phase' => $newPhase,
        'previous_phase' => $previousPhase,
        'location' => 'advanceToNextPhase'
    ]);

    event(new PhaseChangedEvent(...));

    Log::info("🔴🔴🔴 [EXIT] advanceToNextPhase", [
        'match_id' => $match->id,
        'new_phase' => $newPhase,
        'location' => 'advanceToNextPhase'
    ]);
}
```

**¿Es BASE?** ❌ **NO - ES PARA DEBUGGING**

**Uso recomendado:**
- Agregar temporalmente cuando debuggeas race conditions
- Útil para ver flujo de ejecución en logs
- **ELIMINAR después de debugging** para no ensuciar logs en producción

**Mejor práctica:**
- Usar nivel `Log::debug()` en vez de `Log::info()` para estos logs detallados
- En producción, solo `LOG_LEVEL=warning` o `error`
- En desarrollo, `LOG_LEVEL=debug` para ver flujo completo

---

## 🏗️ Arquitectura Event-Driven con Fases

### Principios Fundamentales

Durante el desarrollo descubrimos estos **principios clave** que deben seguir TODOS los juegos con fases:

#### 1. **Separación de Responsabilidades**

```
┌─────────────────┐
│  PhaseManager   │  ← Gestiona fases y timers dentro de una ronda
├─────────────────┤
│  RoundManager   │  ← Gestiona rondas (inicio, fin, progresión)
├─────────────────┤
│  GameEngine     │  ← Lógica específica del juego (callbacks, scoring)
├─────────────────┤
│  Frontend       │  ← UI y estado visual (subscribe a eventos relevantes)
└─────────────────┘
```

**Cada capa:**
- Solo conoce su responsabilidad
- Emite eventos cuando cambia estado
- No conoce quién escucha los eventos (desacoplamiento)

#### 2. **Event Subscription Pattern**

> **"Cada componente se suscribe SOLO a los eventos que le importan"**

Ejemplo en Mentiroso:

```javascript
// capabilities.json - Define qué eventos escucha el frontend
{
  "event_config": {
    "events": {
      "PhaseChangedEvent": {
        "handler": "handlePhaseChanged"  // ✅ Suscrito
      },
      "RoundStartedEvent": {
        "handler": "handleRoundStarted"  // ✅ Suscrito
      },
      "RoundEndedEvent": {
        "handler": "handleRoundEnded"    // ✅ Suscrito
      },
      "SomeOtherEvent": {
        // ❌ No definido = no suscrito = ignorado
      }
    }
  }
}
```

#### 3. **State-Based Event Processing**

> **"Solo procesa eventos relevantes para tu estado actual"**

```javascript
async handlePhaseChanged(event) {
    // 1️⃣ ¿En qué estado estoy?
    const isShowingResults = !document.getElementById('results-phase').classList.contains('hidden');
    const isPlaying = !document.getElementById('playing-state').classList.contains('hidden');

    // 2️⃣ ¿Es relevante este evento para mi estado?
    if (isShowingResults) {
        return; // Ignorar cambios de fase mientras muestro resultados
    }

    if (!isPlaying) {
        return; // Ignorar cambios de fase si no estoy jugando
    }

    // 3️⃣ Procesar evento
    this.updatePhase(event.new_phase);
}
```

#### 4. **Self-Managing Components**

> **"Cada módulo se gestiona a sí mismo mediante event listeners"**

Ejemplo: `CancelPhaseManagerTimersOnRoundEnd`
- PhaseManager no necesita ser cancelado manualmente por el juego
- El listener detecta automáticamente cuando termina una ronda
- Se ejecuta solo si el juego usa PhaseManager
- **Auto-gestión completa**

```php
// ✅ El juego NO necesita hacer esto:
// $this->phaseManager->cancelAllTimers(); // ❌ No necesario

// ✅ El listener lo hace automáticamente:
class CancelPhaseManagerTimersOnRoundEnd
{
    public function handle(RoundEndedEvent $event): void
    {
        if (!isset($gameState['phase_manager'])) {
            return; // Solo actúa si hay PhaseManager
        }

        // Auto-cancelación
        $phaseManager->cancelAllTimers();
    }
}
```

---

## 📊 Flujo Completo de una Ronda con Fases

### Backend Flow

```
1. RoundManager::handleNewRound()
   └─> Emite: RoundStartedEvent

2. GameEngine::onRoundStarted()
   └─> PhaseManager::reset() + start()
   └─> Emite: PhaseChangedEvent (phase: 'preparation', timing: 15s)

3. [15 segundos después]
   PhaseManager timer expira
   └─> Ejecuta callback: onPhaseExpired('preparation')
   └─> GameEngine::advanceToNextPhase()
   └─> Emite: PhaseChangedEvent (phase: 'persuasion', timing: 30s)

4. [30 segundos después]
   PhaseManager timer expira
   └─> Ejecuta callback: onPhaseExpired('persuasion')
   └─> GameEngine::advanceToNextPhase()
   └─> Emite: PhaseChangedEvent (phase: 'voting', timing: 10s)

5. [10 segundos después]
   PhaseManager timer expira (última fase)
   └─> Ejecuta callback: onPhaseExpired('voting')
   └─> GameEngine::endCurrentRound()
   └─> RoundManager::completeRound()
   └─> Emite: RoundEndedEvent (delay: 10s)

6. CancelPhaseManagerTimersOnRoundEnd Listener
   └─> Detecta RoundEndedEvent
   └─> Cancela timers de PhaseManager
   └─> Resetea fases a index 0

7. [Frontend llama /start-next-round después de countdown]
   RoundManager::handleNewRound()
   └─> Volver al paso 1
```

### Frontend Flow

```
1. Recibe: RoundStartedEvent
   └─> BaseGameClient::handleRoundStarted()
   └─> Muestra UI de ronda (sin timer)
   └─> Estado: "waiting for phase event"

2. Recibe: PhaseChangedEvent (preparation)
   └─> MentirosoGameClient::handlePhaseChanged()
   └─> Muestra fase preparation
   └─> BaseGameClient::startTimer(15s)  ← Timer sincronizado con servidor

3. Recibe: PhaseChangedEvent (persuasion)
   └─> MentirosoGameClient::handlePhaseChanged()
   └─> Muestra fase persuasion
   └─> BaseGameClient::startTimer(30s)

4. Recibe: PhaseChangedEvent (voting)
   └─> MentirosoGameClient::handlePhaseChanged()
   └─> Muestra fase voting
   └─> BaseGameClient::startTimer(10s)

5. Recibe: RoundEndedEvent
   └─> BaseGameClient::handleRoundEnded()
   └─> Muestra pantalla de resultados
   └─> Inicia countdown 10s
   └─> Estado: "showing results" ← ⚠️ Ignorar PhaseChangedEvent

6. [Countdown termina]
   └─> BaseGameClient llama /start-next-round
   └─> Volver al paso 1
```

### 🛡️ Punto Crítico: Defensive Check

```
5. RoundEndedEvent → Mostrar resultados + countdown 10s
   │
   ├─> Backend: Emite PhaseChangedEvent (nueva ronda)
   │   └─> ❌ handlePhaseChanged() → IGNORADO (defensive check)
   │       Razón: results-phase está visible
   │
   └─> [10s después] Countdown termina
       └─> Frontend: POST /start-next-round
           └─> ✅ handleRoundStarted() → Procesar nueva ronda
```

**Sin defensive check:**
- PhaseChangedEvent procesado inmediatamente
- Oculta results-phase
- Usuario ve resultados por 1 segundo (bug)

**Con defensive check:**
- PhaseChangedEvent ignorado mientras results visible
- Resultados se muestran 10 segundos completos
- Nueva ronda empieza después del countdown

---

## ✅ Checklist: Implementar Juego con Fases

Si vas a crear un juego nuevo con **múltiples fases por ronda**, sigue este checklist:

### Backend

- [ ] **1. Definir fases en constructor del Engine**
  ```php
  protected function initializePhaseManager(): void
  {
      $this->phaseManager = new PhaseManager([
          ['name' => 'preparation', 'duration' => 15],
          ['name' => 'action', 'duration' => 30],
          ['name' => 'resolution', 'duration' => 10]
      ]);
  }
  ```

- [ ] **2. Registrar callbacks para cada fase**
  ```php
  $this->phaseManager->onPhaseExpired('preparation', function($match) {
      $this->advanceToNextPhase($match);
  });

  $this->phaseManager->onPhaseExpired('action', function($match) {
      $this->advanceToNextPhase($match);
  });

  $this->phaseManager->onPhaseExpired('resolution', function($match) {
      $this->endCurrentRound($match); // Última fase
  });
  ```

- [ ] **3. Iniciar PhaseManager en onRoundStarted()**
  ```php
  public function onRoundStarted(Match $match): void
  {
      $this->phaseManager->reset($this->timerService, $match);
      $this->phaseManager->start($this->timerService, $match);

      // Emitir PhaseChangedEvent para la primera fase
      $this->advanceToNextPhase($match);
  }
  ```

- [ ] **4. Usar Cache::lock() en endCurrentRound()**
  ```php
  protected function endCurrentRound(Match $match): void
  {
      $lock = Cache::lock("complete_round:{$match->id}", 10);

      if (!$lock->get()) {
          return; // Otro proceso ya está completando
      }

      try {
          $this->completeRound($match, $results, $scores);
      } finally {
          $lock->release();
      }
  }
  ```

- [ ] **5. NO cancelar manualmente PhaseManager timers**
  - El listener `CancelPhaseManagerTimersOnRoundEnd` lo hace automáticamente

### Frontend

- [ ] **6. Registrar eventos en capabilities.json**
  ```json
  {
    "event_config": {
      "events": {
        "PhaseChangedEvent": {
          "name": "game.phase.changed",
          "handler": "handlePhaseChanged"
        }
      }
    }
  }
  ```

- [ ] **7. Implementar handlePhaseChanged() con defensive check**
  ```javascript
  async handlePhaseChanged(event) {
      const { new_phase, additional_data } = event;

      // Defensive check: ignorar si estamos en resultados
      const resultsPhase = document.getElementById('results-phase');
      if (resultsPhase && !resultsPhase.classList.contains('hidden')) {
          console.log('Ignoring PhaseChangedEvent - showing results');
          return;
      }

      // Procesar fase
      this.updatePhase(new_phase);

      // Timer se inicia automáticamente en BaseGameClient
  }
  ```

- [ ] **8. Dejar que BaseGameClient maneje el timer**
  ```javascript
  // ✅ BaseGameClient::handlePhaseChanged() ya hace:
  // - Extraer timing de additional_data
  // - Llamar startTimer() con server_time sincronizado
  // - Mostrar countdown en UI

  // ❌ NO llamar manualmente startTimer() en el juego
  ```

### Testing

- [ ] **9. Probar flujo completo de ronda**
  - Verificar que todas las fases se ejecutan en orden
  - Confirmar que timers se muestran correctamente
  - Validar que resultados se muestran 10 segundos

- [ ] **10. Probar reconexión en cada fase**
  - Desconectar en preparation → Reconectar → Timer correcto
  - Desconectar en action → Reconectar → Timer correcto
  - Desconectar en resolution → Reconectar → Timer correcto
  - Desconectar en results → Reconectar → Ver resultados

- [ ] **11. Probar race conditions**
  - Múltiples jugadores votando simultáneamente
  - Timer expirando mientras se procesa acción
  - Dos procesos intentando completar ronda simultáneamente

---

## 🚨 Antipatrones a Evitar

### ❌ 1. Flags booleanos para prevenir duplicados

```php
// ❌ MAL
protected bool $_completing_round = false;

protected function endCurrentRound(Match $match): void
{
    if ($this->_completing_round) {
        return;
    }

    $this->_completing_round = true;
    // ... lógica
}
```

**Problema:** Si hay exception o early return, el flag nunca se limpia.

**Solución:** Usar Cache::lock() con try/finally.

### ❌ 2. Cancelar timers manualmente

```php
// ❌ MAL
protected function endCurrentRound(Match $match): void
{
    $this->phaseManager->cancelAllTimers(); // ❌ No necesario
    $this->completeRound($match);
}
```

**Problema:** Acoplamiento innecesario. El listener ya lo hace.

**Solución:** Confiar en `CancelPhaseManagerTimersOnRoundEnd` listener.

### ❌ 3. Procesar todos los eventos sin filtrar

```javascript
// ❌ MAL
async handlePhaseChanged(event) {
    // Procesar siempre, sin importar el estado actual
    this.updatePhase(event.new_phase);
}
```

**Problema:** Eventos irrelevantes sobrescriben estado actual (ej: resultados).

**Solución:** Defensive checks basados en estado actual.

### ❌ 4. Iniciar nueva ronda inmediatamente después de endCurrentRound()

```php
// ❌ MAL
protected function endCurrentRound(Match $match): void
{
    $this->completeRound($match, $results, $scores);
    $this->handleNewRound($match); // ❌ No dar tiempo para mostrar resultados
}
```

**Problema:** Frontend no tiene tiempo para mostrar resultados.

**Solución:** RoundManager emite RoundEndedEvent con delay. Frontend llama /start-next-round después.

---

## 📝 Resumen: ¿Qué es BASE y qué es ESPECÍFICO?

### ✅ Funcionalidad BASE (aplicar a todos los juegos con fases)

| Componente | Descripción | Archivo |
|------------|-------------|---------|
| **CancelPhaseManagerTimersOnRoundEnd** | Auto-cancelación de timers al terminar ronda | `app/Listeners/CancelPhaseManagerTimersOnRoundEnd.php` |
| **Cache::lock() pattern** | Prevenir race conditions con locks en vez de flags | Cualquier GameEngine |
| **Event subscription pattern** | Componentes solo escuchan eventos relevantes | `capabilities.json` |
| **State-based processing** | Ignorar eventos irrelevantes para estado actual | GameClient.js |
| **PhaseManager callbacks** | Registrar onPhaseExpired() para cada fase | GameEngine |

### 🟡 Funcionalidad ESPECÍFICA (depende del juego)

| Componente | Descripción | Cuándo usar |
|------------|-------------|-------------|
| **Defensive check en handlePhaseChanged()** | Ignorar PhaseChangedEvent durante resultados | Solo si tienes fases dentro de rondas |
| **Múltiples fases por ronda** | PhaseManager con 2+ fases | Solo juegos como Mentiroso, no Trivia/UNO |
| **Logging con emojis 🚀🔵🔴** | Debugging de race conditions | Solo durante desarrollo, eliminar en producción |

---

## 🎓 Lecciones Aprendidas

### 1. **Event-Driven Architecture**

> "No preguntes, escucha. No llames, emite."

- Cada módulo emite eventos cuando cambia estado
- Otros módulos se suscriben a eventos relevantes
- Desacoplamiento total entre componentes

### 2. **Self-Managing Components**

> "Si un módulo puede gestionarse solo mediante eventos, debe hacerlo."

- PhaseManager se auto-cancela al recibir RoundEndedEvent
- No requiere que el juego lo cancele manualmente
- Reduce acoplamiento y errores

### 3. **State-Based Event Processing**

> "No todos los eventos son relevantes todo el tiempo."

- Verifica estado actual antes de procesar evento
- Ignora eventos que no aplican a tu estado
- Previene bugs de UI (ej: resultados desapareciendo)

### 4. **Concurrency First**

> "Si múltiples procesos pueden ejecutarlo simultáneamente, usa locks."

- Cache::lock() en vez de flags booleanos
- Auto-liberación con timeout
- try/finally garantiza limpieza

### 5. **Trust the System**

> "Si la arquitectura base ya lo maneja, no lo reimplementes."

- Confía en los listeners globales (CancelPhaseManagerTimersOnRoundEnd)
- Confía en BaseGameClient para timers sincronizados
- Confía en EventManager para enrutamiento de eventos

---

## 🔗 Referencias

- **PhaseManager:** `app/Services/Modules/TurnSystem/PhaseManager.php`
- **CancelPhaseManagerTimersOnRoundEnd:** `app/Listeners/CancelPhaseManagerTimersOnRoundEnd.php`
- **BaseGameClient:** `resources/js/game-core/BaseGameClient.js`
- **Mentiroso Engine:** `games/mentiroso/MentirosoEngine.php`
- **Mentiroso Client:** `games/mentiroso/js/MentirosoGameClient.js`

---

**Conclusión:**

Mentiroso fue el **primer juego con múltiples fases por ronda**, lo que expuso varios bugs y antipatrones en el sistema base. Las lecciones aprendidas han sido documentadas y las mejoras aplicables se han integrado como funcionalidad base para futuros juegos.

**Cualquier juego nuevo con fases debe:**
1. ✅ Usar PhaseManager con callbacks
2. ✅ Confiar en CancelPhaseManagerTimersOnRoundEnd listener
3. ✅ Usar Cache::lock() en vez de flags
4. ✅ Implementar defensive checks basados en estado
5. ✅ Dejar que BaseGameClient maneje timers sincronizados
