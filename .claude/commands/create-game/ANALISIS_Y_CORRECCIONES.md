# 🔍 Análisis del Comando /create-game y Correcciones Necesarias

## 📊 Resumen Ejecutivo

Después de implementar Trivia y comparar con MockupGame, se identificaron **errores críticos** en el comando `/create-game` y en la documentación que causan fallos comunes.

---

## 🚨 ERRORES CRÍTICOS ENCONTRADOS

### ✅ CORRECCIÓN APLICADA: Método `unlockAllPlayers()` implementado

**Estado actual**:
- ✅ `unlockAllPlayers()` ahora existe en `PlayerManager`
- ✅ Usa internamente `unlockPlayer()` para mantener consistencia (DRY)
- ✅ Emite `PlayersUnlockedEvent` automáticamente
- ✅ TriviaEngine actualizado para usar `unlockAllPlayers()`
- ✅ MockupEngine actualizado para usar `unlockAllPlayers()`

**Diferencia entre métodos**:

```php
// ✅ unlockAllPlayers() - Solo desbloquea, NO resetea acciones/customStates
$playerManager->unlockAllPlayers($match, ['fromNewRound' => true]);
// - Desbloquea todos los jugadores
// - NO limpia actions, customStates, attempts
// - Emite PlayersUnlockedEvent automáticamente

// ✅ reset() - Desbloquea Y resetea todo
$playerManager->reset($match, ['fromNewRound' => true]);
// - Desbloquea todos los jugadores
// - Limpia actions, customStates, attempts
// - Emite PlayersUnlockedEvent automáticamente

// ✅ unlockPlayer() - Desbloquea un solo jugador
$playerManager->unlockPlayer($playerId);
// - Solo desbloquea un jugador específico
// - NO emite eventos
```

**Cuándo usar cada uno**:
- `unlockAllPlayers()`: Cuando solo necesitas desbloquear sin perder datos (ej: al inicio de nueva ronda si quieres mantener acciones anteriores)
- `reset()`: Cuando necesitas limpiar TODO y empezar desde cero (recomendado para mayoría de casos)
- `unlockPlayer()`: Cuando necesitas desbloquear un jugador específico sin afectar a otros

---

### ❌ ERROR 2: Falta explicar hook `onRoundStarting()`

**Problema crítico encontrado en Trivia**:
- Los eventos de fase (`Phase1StartedEvent`) se emitían ANTES de que el UI estuviera establecido
- Resultado: Eventos llegaban sin `question_text`, `options`, etc.

**Solución descubierta**:
- Implementar `onRoundStarting()` que llama a `startNewRound()`
- `onRoundStarting()` se ejecuta ANTES de que `PhaseManager` emita eventos
- Flujo correcto: `onRoundStarting()` → `startNewRound()` → establecer UI → guardar → eventos emitidos

**Comparación con MockupGame**:
- Mockup NO implementa `onRoundStarting()`
- Mockup establece UI en `initialize()` pero no para cada ronda nueva
- Mockup probablemente no necesita esto porque no prepara datos dinámicos por ronda

**Cuándo usar `onRoundStarting()`**:
- ✅ SÍ: Si necesitas cargar datos específicos de la ronda (preguntas, palabras, etc.)
- ✅ SÍ: Si necesitas establecer UI en `game_state['_ui']` antes de que se emita `Phase1StartedEvent`
- ❌ NO: Si solo necesitas desbloquear jugadores (eso lo hace `startNewRound()`)

**Ejemplo de Trivia (correcto)**:
```php
protected function onRoundStarting(GameMatch $match): void
{
    // Llamar a startNewRound() que establece el UI con la pregunta
    $this->startNewRound($match);
    
    // Log UI state snapshot after startNewRound
    Log::info('[Trivia] UI state after onRoundStarting/startNewRound', [
        'question_text' => $match->game_state['_ui']['phases']['question']['text'] ?? 'MISSING',
        'options_count' => count($match->game_state['_ui']['phases']['answering']['options'] ?? []),
        'correct_option' => $match->game_state['_ui']['phases']['answering']['correct_option'] ?? 'MISSING',
    ]);
}

protected function startNewRound(GameMatch $match): void
{
    // Desbloquear jugadores
    $playerManager = $this->getPlayerManager($match);
    $playerManager->reset($match, ['fromNewRound' => true]);
    $this->savePlayerManager($match, $playerManager);
    
    // Seleccionar pregunta y establecer UI
    [$question, $options, $correctIndex] = $this->selectQuestion($match, ...);
    $this->setUI($match, 'phases.question.text', $question);
    $this->setUI($match, 'phases.answering.options', $options);
    $this->setUI($match, 'phases.answering.correct_option', $correctIndex);
    $match->save(); // CRÍTICO: guardar antes de que se emita el evento
}
```

**Orden correcto de ejecución en BaseGameEngine**:
```
handleNewRound():
  1. Avanzar ronda
  2. Resetear jugadores (automático)
  3. Rotar roles (automático)
  4. ✅ onRoundStarting() hook ← AQUÍ preparar datos
  5. Emitir RoundStartedEvent
  6. PhaseManager.startPhase() → emite Phase1StartedEvent
  7. onRoundStarted() hook ← AQUÍ lógica post-evento
```

---

## 📋 CAMBIOS NECESARIOS EN `/create-game`

### ✅ FASE 7: Engine - Estructura Base

**AGREGAR sección sobre `onRoundStarting()`**:

```markdown
### Hook: onRoundStarting() (OPCIONAL pero RECOMENDADO)

**CUÁNDO USAR**:
- Si necesitas cargar datos específicos de la ronda (preguntas, palabras, etc.)
- Si necesitas establecer UI (`game_state['_ui']`) ANTES de que se emita el primer evento de fase

**CUÁNDO NO USAR**:
- Si solo necesitas desbloquear jugadores (eso lo hace automáticamente `handleNewRound()`)
- Si todos los datos están en `initialize()` y no cambian por ronda

**EJEMPLO**:
```php
protected function onRoundStarting(GameMatch $match): void
{
    // Este hook se ejecuta ANTES de emitir RoundStartedEvent
    // y ANTES de que PhaseManager emita Phase1StartedEvent
    
    // Llamar a startNewRound() que prepara datos específicos
    $this->startNewRound($match);
    
    // Verificar que UI está establecida
    Log::info('[Game] UI state after onRoundStarting', [
        'has_ui_data' => !empty($match->game_state['_ui']),
    ]);
}
```

**⚠️ IMPORTANTE**:
- `onRoundStarting()` se ejecuta ANTES de los eventos
- `startNewRound()` debe guardar (`$match->save()`) después de establecer UI
- Esto garantiza que los eventos incluyan los datos correctos en `broadcastWith()`
```

### ✅ FASE 8: Engine - Ciclo de Rondas

**CORREGIR sección de `startNewRound()`**:

```markdown
## Task 2: Implementar startNewRound()

```php
protected function startNewRound(GameMatch $match): void
{
    // 1. Desbloquear jugadores usando reset() (NO unlockAllPlayers)
    $playerManager = $this->getPlayerManager($match);
    // ✅ CORRECTO: reset() desbloquea y emite PlayersUnlockedEvent automáticamente
    $playerManager->reset($match, ['fromNewRound' => true]);
    $this->savePlayerManager($match, $playerManager);
    
    // ❌ INCORRECTO: unlockAllPlayers() NO EXISTE
    // $playerManager->unlockAllPlayers($match); // ← Esto causará error
    
    // 2. Limpiar acciones de ronda anterior
    $gameState = $match->game_state;
    $gameState['actions'] = [];
    $gameState['phase'] = 'playing';
    $match->game_state = $gameState;
    $match->save();
    
    // 3. Preparar datos específicos de la ronda (preguntas, palabras, etc.)
    // Esto se hace AQUÍ si usas onRoundStarting(), o directamente si no lo usas
    $this->loadRoundData($match);
    
    // 4. Establecer UI si es necesario
    $this->setUI($match, 'phases.fase1.data', $roundData);
    $match->save(); // CRÍTICO: guardar antes de que se emitan eventos
}
```

**Checklist**:
- [ ] Usa `reset()` NO `unlockAllPlayers()`
- [ ] Guarda PlayerManager después de reset()
- [ ] Guarda match después de establecer UI
- [ ] Si usas onRoundStarting(), llama a startNewRound() desde ahí
```

---

## 🔄 COMPARACIÓN CON MOCKUPGAME

### ✅ Lo que MockupGame hace BIEN:
1. Estructura clara de fases
2. Uso correcto de `setMatch()` en callbacks
3. Implementación correcta de `handlePhase1Ended()` y `handlePhase2Ended()`
4. Buen ejemplo de filtrado de `game_state`

### ❌ Lo que MockupGame hace MAL:
1. **Línea 245**: Usa `unlockAllPlayers()` que no existe
   - Debería usar `reset()`
   - Esto causará error en ejecución (aunque puede que no se haya detectado todavía)

2. **No implementa `onRoundStarting()`**:
   - No es crítico porque Mockup no carga datos dinámicos por ronda
   - Pero si en el futuro necesita cargar datos, debería usarlo

### 📝 Recomendación para MockupGame:
```php
// games/mockup/MockupEngine.php línea 245
// ❌ ACTUAL (incorrecto):
$playerManager->unlockAllPlayers($match);

// ✅ CORRECTO:
$playerManager->reset($match, ['fromNewRound' => true]);
// Nota: Ya no necesitas emitir PlayersUnlockedEvent manualmente,
// reset() lo hace automáticamente si había jugadores bloqueados
```

---

## 📚 ACTUALIZACIONES NECESARIAS EN DOCUMENTACIÓN

### 1. `docs/CREAR_JUEGO_PASO_A_PASO.md`

**AGREGAR sección después de "FASE 5: Engine (Backend)"**:

```markdown
### Hook: onRoundStarting() - Preparar Datos Antes de Eventos

Si tu juego necesita cargar datos específicos de la ronda (preguntas, palabras, etc.)
y estos datos deben estar disponibles cuando se emita el primer evento de fase,
usa el hook `onRoundStarting()`:

```php
protected function onRoundStarting(GameMatch $match): void
{
    // Llamar a startNewRound() que prepara datos
    $this->startNewRound($match);
}
```

**Flujo**:
1. `handleNewRound()` llama a `onRoundStarting()`
2. `onRoundStarting()` llama a `startNewRound()`
3. `startNewRound()` establece UI y guarda
4. `handleNewRound()` emite `RoundStartedEvent`
5. `PhaseManager.startPhase()` emite `Phase1StartedEvent` (con datos ya disponibles)
```

### 2. Actualizar `docs/GUIA_COMPLETA_MOCKUP_GAME.md`

**Agregar nota**:
```markdown
⚠️ NOTA: Este ejemplo usa `unlockAllPlayers()` que está deprecado.
Usa `reset()` en su lugar (ver corrección en este documento).
```

---

## ✅ CHECKLIST DE VALIDACIÓN PARA `/create-game`

**Agregar al checklist final**:

```markdown
### Backend - Métodos y Hooks ✅
- [ ] `startNewRound()` usa `reset()` NO `unlockAllPlayers()`
- [ ] `onRoundStarting()` implementado si necesitas cargar datos antes de eventos
- [ ] UI establecida en `startNewRound()` si se usa `onRoundStarting()`
- [ ] `$match->save()` después de establecer UI (antes de eventos)
- [ ] `PlayerManager` guardado después de `reset()`
- [ ] Todos los callbacks `handle{Fase}Ended()` implementados
- [ ] `$phaseManager->setMatch($match)` en TODOS los callbacks
```

---

## 🎯 RESUMEN DE CAMBIOS REQUERIDOS

1. **Crítico**: Explicar `reset()` vs `unlockAllPlayers()` en FASE 8
2. **Crítico**: Agregar sección sobre `onRoundStarting()` en FASE 7
3. **Importante**: Actualizar MockupEngine para usar `reset()`
4. **Importante**: Agregar al checklist final validación de métodos
5. **Documentación**: Actualizar `CREAR_JUEGO_PASO_A_PASO.md` con hook `onRoundStarting()`

---

## 🚀 PRIORIDAD DE IMPLEMENTACIÓN

1. **PRIORIDAD 1** (Errores que causan fallos):
   - Corregir MockupEngine línea 245
   - Agregar explicación de `reset()` en FASE 8

2. **PRIORIDAD 2** (Mejoras importantes):
   - Agregar sección `onRoundStarting()` en FASE 7
   - Actualizar checklist final

3. **PRIORIDAD 3** (Documentación):
   - Actualizar `CREAR_JUEGO_PASO_A_PASO.md`
   - Nota en `GUIA_COMPLETA_MOCKUP_GAME.md`
