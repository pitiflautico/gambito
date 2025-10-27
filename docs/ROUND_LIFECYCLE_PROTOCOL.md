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
}
```

#### 🎨 Específico del Juego (implementar en XxxEngine)
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

    // RoundManager maneja automáticamente:
    // - Emitir RoundEndedEvent con timing
    // - Incluir auto_next y delay desde config.json
    $roundManager->completeRound($match, $results, $scores);

    // Verificar si el juego terminó
    if ($roundManager->isGameComplete()) {
        $this->finalize($match);
    }
}
```

#### 🎨 Específico del Juego
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

## ✅ Checklist para Nuevos Juegos

Al crear un nuevo juego, asegúrate de implementar:

### Backend (Engine)

- [ ] **startNewRound()**: Lógica de inicio de ronda específica
- [ ] **⚠️ CRÍTICO: savePlayerManager()**: Guardar INMEDIATAMENTE después de reset()
- [ ] **endCurrentRound()**: Obtener resultados y llamar completeRound()
- [ ] **filterGameStateForBroadcast()**: Filtrar información sensible (si aplica)
- [ ] **Emitir eventos privados**: Si hay información que solo ciertos jugadores deben ver
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

**Última actualización**: 2025-01-27
**Autor**: Arquitectura del Sistema
