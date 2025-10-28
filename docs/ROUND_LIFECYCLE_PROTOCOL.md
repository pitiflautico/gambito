# 🔄 Protocolo de Ciclo de Vida de Ronda

## 📋 Descripción

Protocolo estándar que TODOS los juegos deben seguir para garantizar comportamiento consistente en:
- Inicio de ronda
- Fin de ronda
- Reseteo de estado
- Transición entre rondas
- Reconexión de jugadores (refresh del navegador)

## 🎯 Principios del Protocolo

1. **Comportamiento Base Automático**: Lo común debe funcionar sin código específico del juego
2. **Extensibilidad**: Los juegos pueden agregar lógica específica sin romper lo base
3. **Resiliencia**: Debe funcionar correctamente tras reconexión/refresh
4. **Consistencia**: Todos los juegos siguen el mismo flujo

---

## 🔵 1. INICIO DE RONDA (`new_round` / `round_started`)

### Backend: BaseGameEngine

#### ✅ Automático (NO requiere código en el juego)
```php
// BaseGameEngine::handleNewRound()
public function handleNewRound(GameMatch $match, bool $advanceRound = true): void
{
    // 1. Avanzar contador de ronda (via RoundManager)
    $roundManager->nextRound();

    // 2. Resetear timer de ronda
    $timerService->startTimer('round', $duration);

    // 3. Llamar a startNewRound() del juego
    $this->startNewRound($match);

    // 4. Obtener timing metadata
    $timing = $this->getRoundStartTiming($match);

    // 5. Filtrar game_state para broadcast público
    $filteredGameState = $this->filterGameStateForBroadcast($match->game_state, $match);

    // 6. Emitir RoundStartedEvent con timing
    event(new RoundStartedEvent($matchFiltered, $currentRound, $totalRounds, $timing));

    // 7. Llamar al hook onRoundStarted() para que el juego ejecute lógica custom
    $this->onRoundStarted($match, $currentRound, $totalRounds);
}
```

#### 🎨 Específico del Juego (implementar en XxxEngine)

##### Opción 1: Usando startNewRound() (Recomendado para lógica compleja)
```php
// games/pictionary/PictionaryEngine.php
protected function startNewRound(GameMatch $match): void
{
    // 1. Resetear estado temporal (bloqueos, acciones)
    $playerManager = $this->getPlayerManager($match);
    $playerManager->reset($match); // Emite PlayersUnlockedEvent automáticamente

    // ⚠️ CRÍTICO: Guardar el estado INMEDIATAMENTE después del reset
    // Si no se guarda, las llamadas posteriores cargarán el estado viejo
    $this->savePlayerManager($match, $playerManager);

    // 2. Lógica específica del juego
    $this->rotateDrawer($match);
    $word = $this->loadNextWord($match);
    $this->assignRoles($match);

    // 3. Emitir eventos privados si es necesario
    event(new WordRevealedEvent($match, $drawer, $word)); // Canal privado
}
```

##### Opción 2: Usando hook onRoundStarted() (Recomendado para lógica post-evento)
```php
// games/mentiroso/MentirosoEngine.php
protected function onRoundStarted(GameMatch $match, int $currentRound, int $totalRounds): void
{
    // Este hook se ejecuta DESPUÉS de emitir RoundStartedEvent
    // Útil para:
    // - Iniciar timers específicos del juego
    // - Enviar notificaciones privadas a jugadores
    // - Ejecutar lógica de negocio que NO afecta al evento emitido

    // Ejemplo: Enviar frase secreta al orador
    $gameState = $match->game_state;
    $oradorId = $gameState['turn_system']['current_player'];
    $frase = $gameState['current_statement'];

    event(new StatementRevealedEvent($match, $oradorId, $frase));
}
```

#### 🔐 Filtrar Información Sensible
```php
// games/pictionary/PictionaryEngine.php
protected function filterGameStateForBroadcast(array $gameState, GameMatch $match): array
{
    $filtered = $gameState;

    // Remover información que no todos deben ver
    unset($filtered['current_word']); // Solo el drawer debe verla

    return $filtered;
}
```

### Frontend: BaseGameClient

#### ✅ Automático
```javascript
// BaseGameClient::handleRoundStarted()
async handleRoundStarted(event) {
    // 1. Actualizar info de ronda
    this.currentRound = event.current_round;
    this.totalRounds = event.total_rounds;

    // 2. Procesar timing (iniciar countdown automáticamente)
    if (event.timing) {
        this.timing.startServerSyncedCountdown(
            event.timing.server_time,
            event.timing.duration * 1000,
            this.getTimerElement(),
            () => this.onTimerExpired(this.currentRound)
        );
    }
}
```

#### 🎨 Específico del Juego
```javascript
// games/pictionary/js/PictionaryGameClient.js
handleRoundStarted(event) {
    // 1. IMPORTANTE: Llamar al base primero
    super.handleRoundStarted(event);

    // 2. Lógica específica del juego
    this.isDrawer = (event.drawer_id === this.playerId);
    this.clearCanvas();
    this.updateDrawerInfo();
    // ...
}
```

---

## 🔴 2. FIN DE RONDA (`end_round` / `round_ended`)

### Backend: BaseGameEngine

#### ✅ Automático
```php
// BaseGameEngine::completeRound()
protected function completeRound(GameMatch $match, array $results = []): void
{
    $roundManager = $this->getRoundManager($match);
    $scores = $this->getScores($match->game_state);

    // 1. RoundManager maneja automáticamente:
    // - Emitir RoundEndedEvent con timing
    // - Incluir auto_next y delay desde config.json
    $roundManager->completeRound($match, $results, $scores);

    // 2. Llamar al hook onRoundEnded() para que el juego ejecute lógica custom
    $currentRound = $roundManager->getCurrentRound();
    $this->onRoundEnded($match, $currentRound, $results, $scores);

    // 3. Guardar estado actualizado
    $this->saveRoundManager($match, $roundManager);

    // 4. Verificar si el juego terminó
    if ($roundManager->isGameComplete()) {
        $this->finalize($match);
    }
}
```

#### 🎨 Específico del Juego

##### Opción 1: Usando endCurrentRound() (Recomendado para calcular resultados)
```php
// games/pictionary/PictionaryEngine.php
public function endCurrentRound(GameMatch $match): void
{
    // 1. Obtener resultados específicos del juego
    $results = $this->getAllPlayerResults($match);

    // 2. Delegar al base (maneja todo automáticamente)
    $this->completeRound($match, $results);
}
```

##### Opción 2: Usando hook onRoundEnded() (Recomendado para lógica post-evento)
```php
// games/mentiroso/MentirosoEngine.php
protected function onRoundEnded(GameMatch $match, int $roundNumber, array $results, array $scores): void
{
    // Este hook se ejecuta DESPUÉS de emitir RoundEndedEvent
    // Útil para:
    // - Cancelar timers específicos del juego
    // - Calcular estadísticas de la ronda
    // - Preparar datos para la siguiente ronda
    // - Ejecutar lógica de negocio que NO afecta al evento emitido

    // Ejemplo: Registrar estadísticas de la ronda
    Log::info("[Mentiroso] Round {$roundNumber} ended", [
        'results' => $results,
        'scores' => $scores,
    ]);
}
```

### Frontend: BaseGameClient

#### ✅ Automático
```javascript
// BaseGameClient::handleRoundEnded()
async handleRoundEnded(event) {
    // 1. Actualizar scores automáticamente
    if (event.scores) {
        this.scores = event.scores;
    }

    // 2. Procesar timing para auto-next
    if (event.timing?.auto_next) {
        await this.timing.processTimingPoint(
            event.timing,
            this.getCountdownElement(),
            () => this.requestNextRound() // Llama a /api/rooms/{code}/next-round
        );
    }
}
```

#### 🎨 Específico del Juego
```javascript
// games/pictionary/js/PictionaryGameClient.js
handleRoundEnded(event) {
    // 1. Llamar al base primero
    super.handleRoundEnded(event);

    // 2. Mostrar resultados específicos
    this.showResultWord(event.results.word);
    this.showGuessers(event.results.guessers);
    this.showElement('round-results-state');
}
```

---

## 🔄 3. RESET DE ESTADO (`players_unlocked`)

### Backend: PlayerManager

#### ✅ Automático
```php
// PlayerManager::reset()
public function reset(?GameMatch $match = null): void
{
    foreach ($this->players as $player) {
        // Limpiar estado temporal
        $player->locked = false;
        $player->action = null;
        $player->customStates = [];
        $player->attempts = 0;
        // NO resetear: scores, roles persistentes
    }

    // Emitir PlayersUnlockedEvent automáticamente
    if ($match) {
        event(new PlayersUnlockedEvent($match));
    }
}
```

### Frontend: BaseGameClient

#### 🎨 Específico del Juego (debe implementarse)
```javascript
// games/pictionary/js/PictionaryGameClient.js
handlePlayersUnlocked(event) {
    // Resetear estado local
    this.isLocked = false;

    // Resetear UI
    this.hideElement('waiting-validation');
    this.hideElement('correct-overlay');
    this.showElement('claim-section');
}
```

---

## 🔁 4. TRANSICIÓN ENTRE RONDAS

### Flujo Completo

```
1. Ronda termina → Engine llama endCurrentRound()
2. endCurrentRound() → completeRound() → RoundManager
3. RoundManager emite RoundEndedEvent con timing: {auto_next: true, delay: 5}
4. Frontend recibe evento, muestra resultados
5. Frontend inicia countdown de 5 segundos
6. Al terminar countdown → Frontend llama POST /api/rooms/{code}/next-round
7. Endpoint llama handleNewRound(advanceRound: true)
8. handleNewRound() → startNewRound() del juego
9. handleNewRound() emite RoundStartedEvent
10. Cycle continúa...
```

### Config del Juego (config.json)
```json
{
  "timing": {
    "round_start": {
      "duration": 30,
      "countdown_visible": true,
      "warning_threshold": 5
    },
    "round_ended": {
      "auto_next": true,
      "delay": 5,
      "message": "Siguiente ronda"
    }
  }
}
```

---

## 🔌 5. RECONEXIÓN / REFRESH DEL NAVEGADOR

### Problema
Cuando un jugador refresca (F5):
- Pierde el estado local del frontend
- Necesita restaurar: ronda actual, timer, roles, información privada

### Solución: Carga de Estado Inicial

#### Backend: `/api/rooms/{code}/state`
```php
// RoomController::apiGetState()
public function apiGetState(string $code)
{
    $match = $room->match;

    // Retornar game_state COMPLETO (sin filtrar)
    // El frontend decide qué mostrar según el rol del jugador
    return response()->json([
        'game_state' => $match->game_state, // SIN filtrar
        'players' => $players,
    ]);
}
```

#### Frontend: Restauración de Estado (game.blade.php)
```javascript
// 1. Cargar estado desde API
const response = await fetch(`/api/rooms/${roomCode}/state`);
const { game_state, players } = await response.json();

// 2. Cargar players y scores
gameClient.players = players;
gameClient.scores = extractScores(game_state.player_system);
gameClient.renderPlayersList();

// 3. Si el juego está en playing, simular evento de ronda
if (game_state?.phase === 'playing') {
    const eventData = {
        current_round: game_state.round_system?.current_round,
        total_rounds: game_state.round_system?.total_rounds,
        game_state: game_state,
        // Timing desde timer activo
        timing: extractTimingFromActiveTimer(game_state.timer_system)
    };

    gameClient.handleRoundStarted(eventData);

    // 4. Restaurar información privada según rol
    if (isDrawer(game_state, playerId)) {
        const word = game_state.current_word; // Disponible sin filtrar
        if (word) {
            gameClient.handleWordRevealed({
                word: word.word,
                difficulty: word.difficulty,
                round_number: game_state.round_system.current_round
            });
        }
    }

    // 5. Restaurar locks
    if (game_state.player_system?.players?.[playerId]?.locked) {
        gameClient.isLocked = true;
        // Mostrar UI de bloqueado
    }

    // 6. Restaurar canvas (si aplica)
    game_state.canvas_data?.forEach(stroke => {
        gameClient.renderStroke(stroke);
    });
}

// 4. Si el juego está finished, simular evento de juego terminado
if (game_state?.phase === 'finished') {
    const finishedEvent = {
        winner: game_state.winner,
        ranking: game_state.ranking,
        scores: gameClient.scores,
        game_state: game_state
    };

    gameClient.handleGameFinished(finishedEvent);
}
```

---

## 🪝 6. SISTEMA DE HOOKS

### ¿Qué son los Hooks?

Los hooks son métodos protegidos vacíos en `BaseGameEngine` que se ejecutan **DESPUÉS** de que se emitan los eventos del sistema. Permiten a los juegos específicos extender el comportamiento sin modificar el flujo base.

### Hooks Disponibles

#### onRoundStarted()
```php
/**
 * Hook: Ejecutado DESPUÉS de emitir RoundStartedEvent.
 *
 * @param GameMatch $match
 * @param int $currentRound
 * @param int $totalRounds
 * @return void
 */
protected function onRoundStarted(GameMatch $match, int $currentRound, int $totalRounds): void
{
    // Implementación vacía por defecto
}
```

**Cuándo usar:**
- ✅ Enviar eventos privados a jugadores específicos (ej: revelar frase al orador)
- ✅ Iniciar timers específicos del juego
- ✅ Ejecutar lógica que NO afecta al `RoundStartedEvent` ya emitido

**Cuándo NO usar:**
- ❌ Modificar `game_state` que debería estar en el evento (usar `startNewRound()` en su lugar)
- ❌ Resetear estado de jugadores (usar `startNewRound()` en su lugar)

#### onRoundEnded()
```php
/**
 * Hook: Ejecutado DESPUÉS de emitir RoundEndedEvent.
 *
 * @param GameMatch $match
 * @param int $roundNumber
 * @param array $results
 * @param array $scores
 * @return void
 */
protected function onRoundEnded(GameMatch $match, int $roundNumber, array $results, array $scores): void
{
    // Implementación vacía por defecto
}
```

**Cuándo usar:**
- ✅ Cancelar timers específicos del juego
- ✅ Calcular estadísticas de la ronda
- ✅ Preparar datos para la siguiente ronda
- ✅ Logging/debugging

**Cuándo NO usar:**
- ❌ Calcular resultados (usar `endCurrentRound()` en su lugar)
- ❌ Modificar scores (ya se emitieron en el evento)

### Diferencia entre startNewRound() y onRoundStarted()

| Aspecto | startNewRound() | onRoundStarted() |
|---------|-----------------|------------------|
| **Cuándo se ejecuta** | ANTES de emitir RoundStartedEvent | DESPUÉS de emitir RoundStartedEvent |
| **Propósito** | Preparar estado del juego | Ejecutar lógica post-evento |
| **Modificar game_state** | ✅ Sí (visible en evento) | ⚠️ Posible pero no recomendado |
| **Emitir eventos privados** | ✅ Sí | ✅ Sí (recomendado aquí) |
| **Resetear módulos** | ✅ Sí (recomendado aquí) | ❌ No |
| **Timing** | Sincrónico con evento | Post-evento |

### Ejemplo Completo: Mentiroso

```php
// games/mentiroso/MentirosoEngine.php

// 1. startNewRound() - Prepara estado del juego
protected function startNewRound(GameMatch $match): void
{
    $playerManager = $this->getPlayerManager($match);
    $playerManager->reset($match); // Resetea bloqueos
    $this->savePlayerManager($match, $playerManager);

    // Seleccionar frase y guardar en game_state
    $frase = $this->selectRandomStatement();
    $gameState = $match->game_state;
    $gameState['current_statement'] = $frase;
    $match->game_state = $gameState;
    $match->save();

    // Ahora RoundStartedEvent incluirá current_statement (filtrado)
}

// 2. onRoundStarted() - Envía evento privado DESPUÉS del evento público
protected function onRoundStarted(GameMatch $match, int $currentRound, int $totalRounds): void
{
    // El orador necesita saber si la frase es verdadera o falsa
    // Esto NO debe estar en el evento público RoundStartedEvent
    $gameState = $match->game_state;
    $oradorId = $gameState['turn_system']['current_player'];
    $frase = $gameState['current_statement'];

    // Emitir evento PRIVADO solo al orador
    event(new StatementRevealedEvent($match, $oradorId, $frase));
}
```

---

## ✅ Checklist para Nuevos Juegos

Al crear un nuevo juego, asegúrate de implementar:

### Backend (Engine)

- [ ] **startNewRound()**: Lógica de inicio de ronda específica (resetear módulos, preparar game_state)
- [ ] **⚠️ CRÍTICO: savePlayerManager()**: Guardar INMEDIATAMENTE después de reset()
- [ ] **onRoundStarted()** (opcional): Hook para lógica post-evento (enviar eventos privados, iniciar timers)
- [ ] **endCurrentRound()**: Obtener resultados y llamar completeRound()
- [ ] **onRoundEnded()** (opcional): Hook para lógica post-evento (cancelar timers, estadísticas, logging)
- [ ] **filterGameStateForBroadcast()**: Filtrar información sensible (si aplica)
- [ ] **Emitir eventos privados**: Si hay información que solo ciertos jugadores deben ver (idealmente en hooks)
- [ ] **Config timing**: Definir `round_start` y `round_ended` en config.json

### Frontend (GameClient)

- [ ] **handleRoundStarted()**: Llamar a `super.handleRoundStarted()` + lógica específica
- [ ] **handleRoundEnded()**: Llamar a `super.handleRoundEnded()` + mostrar resultados
- [ ] **handlePlayersUnlocked()**: Resetear locks y UI
- [ ] **handleGameFinished()**: Llamar a `super.handleGameFinished()` + `renderPodium()`
- [ ] **getTimerElement()**: Retornar elemento HTML donde mostrar timer
- [ ] **getCountdownElement()**: Retornar elemento para countdown entre rondas
- [ ] **Restauración de estado 'playing'**: Lógica en game.blade.php para reconexión durante partida
- [ ] **Restauración de estado 'finished'**: Lógica en game.blade.php para reconexión tras finalizar
- [ ] **Registrar eventos**: Agregar todos los handlers en capabilities.json

---

## 🎓 Ejemplos de Referencia

### ✅ Pictionary (Completo)
- Implementa TODOS los métodos del protocolo
- Usa canal privado para `WordRevealedEvent`
- Filtra `current_word` en broadcasts públicos
- Restaura palabra al drawer en reconexión

### ⚠️ Trivia (Parcial - pendiente migración)
- Implementa handlers básicos
- Falta migración a PlayerManager
- Falta restauración completa en reconexión

---

## 📚 Referencias

- **BaseGameEngine.php** - Implementación del protocolo base
- **PlayerManager.php** - Reset automático de estado
- **RoundManager.php** - Gestión de rondas y timing
- **BaseGameClient.js** - Handlers automáticos de eventos
- **TimingModule.js** - Gestión de countdowns y timers

---

## 🔧 Troubleshooting

### "El timer no se muestra"
✅ Implementar `getTimerElement()` en tu GameClient
✅ Llamar a `super.handleRoundStarted()` en tu override

### "Los bloqueos no se resetean"
✅ Implementar `handlePlayersUnlocked()` en tu GameClient
✅ Llamar a `playerManager->reset($match)` en startNewRound()
✅ **CRÍTICO**: Llamar a `$this->savePlayerManager($match, $playerManager)` INMEDIATAMENTE después del reset

### "La información privada desaparece al refrescar"
✅ Restaurar información desde `game_state` en game.blade.php
✅ NO filtrar información privada en `/api/rooms/{code}/state`

### "El countdown entre rondas no funciona"
✅ Configurar `timing.round_ended` en config.json
✅ Implementar `getCountdownElement()` en tu GameClient
✅ Llamar a `super.handleRoundEnded()` en tu override

---

**Última actualización**: 2025-10-28
**Autor**: Arquitectura del Sistema
**Cambios**:
- Añadido sistema de hooks: `onRoundStarted()` y `onRoundEnded()`
- Documentación de cuándo usar cada hook vs métodos tradicionales
- Ejemplos completos con Mentiroso
