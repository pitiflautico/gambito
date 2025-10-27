# Task List: Pictionary Game Implementation

**Based on PRD:** `prds/game-pictionary.md`

**Status:** ✅ Complete task list with detailed sub-tasks.

---

## Relevant Files

### Backend (To Create/Modify)
- `games/pictionary/PictionaryEngine.php` - Game engine with word selection, drawer rotation, guess validation, and round management. Currently has basic structure with TODOs.
- `games/pictionary/PictionaryScoreCalculator.php` - ✅ Complete - Calculates points for guessers (base + speed bonus) and drawer.
- `games/pictionary/capabilities.json` - ✅ Complete - Defines event_config with custom events and required capabilities.
- `games/pictionary/config.json` - ✅ Updated - Added all required fields (id, minPlayers, maxPlayers, estimatedDuration, etc).
- `games/mockup/MockupEngine.php` - ✅ Fixed - Added missing `getFinalScores()` method.
- `app/Events/Pictionary/DrawStrokeEvent.php` - **TO CREATE** - WebSocket event for broadcasting canvas strokes.
- `app/Events/Pictionary/WordRevealedEvent.php` - **TO CREATE** - Reveals word to drawer only.
- `app/Events/Pictionary/CorrectGuessEvent.php` - **TO CREATE** - Broadcast when player guesses correctly.
- `app/Events/Pictionary/CanvasClearedEvent.php` - **TO CREATE** - Broadcast when drawer clears canvas.

### Frontend (To Complete)
- `games/pictionary/views/game.blade.php` - Game UI with canvas, tools, guess input. Currently has placeholder structure.
- `games/pictionary/js/PictionaryGameClient.js` - Client-side game logic. Currently has skeleton with TODOs.

### Data Files (Already Created)
- `games/pictionary/config.json` - ✅ Complete - Module configuration and settings.
- `games/pictionary/words.json` - ✅ Complete - 90 words across 3 difficulty levels.
- `games/pictionary/rules.json` - ✅ Complete - Game rules and tips.

### Tests (To Create)
- `tests/Feature/Games/PictionaryGameFlowTest.php` - Feature tests for complete game flow (initialize → rounds → finish).
- `tests/Feature/Games/PictionaryGuessTest.php` - Tests for guess validation, normalization, and scoring.
- `tests/Feature/Games/PictionaryDrawerRotationTest.php` - Tests for drawer rotation and role assignment.
- `tests/Unit/PictionaryScoreCalculatorTest.php` - Unit tests for score calculation (already fully implemented).

---

## Tasks

* [ ] 1.0 **Backend Engine - Core Game Logic**

  Complete the PictionaryEngine.php implementation with word selection, drawer rotation, guess validation, and round management.

  * [ ] 1.1 Complete `selectWords()` method to filter words by difficulty (easy/medium/hard/mixed) and ensure no duplicates in the same game session
  * [ ] 1.2 Implement drawer rotation in `onGameStart()` - shuffle player IDs and store in `drawer_rotation` array
  * [ ] 1.3 Complete `processGuess()` - normalize guess string (lowercase, remove accents, trim) and compare with current word
  * [ ] 1.4 Implement player locking in `processGuess()` - lock guesser after correct answer, check if all guessers locked to end round
  * [ ] 1.5 Complete `processDrawStroke()` - validate drawer, store stroke in `canvas_data`, prepare for broadcasting
  * [ ] 1.6 Implement `rotateDrawer()` - advance `current_drawer_index` and wrap around to ensure all players get equal turns
  * [ ] 1.7 Complete `assignRoles()` in `startNewRound()` - set drawer role for current drawer, guesser role for all others
  * [ ] 1.8 Implement `handlePlayerDisconnect()` - if current drawer disconnects, auto-skip to next round using `endCurrentRound()`
  * [ ] 1.9 Test engine initialization with different player counts (2, 4, 8, 10) and verify drawer rotation cycles correctly

* [ ] 2.0 **Canvas Drawing System - Frontend & Backend**

  Implement HTML5 canvas drawing tools, mouse/touch events, stroke capture, and backend stroke broadcasting via WebSockets.

  * [ ] 2.1 Initialize canvas in `PictionaryGameClient.initializeCanvas()` - set white background, configure line cap/join styles
  * [ ] 2.2 Implement `startDrawing()` - handle mousedown/touchstart, begin path, record starting point
  * [ ] 2.3 Implement `draw()` - handle mousemove/touchmove, draw line to current position, accumulate stroke points
  * [ ] 2.4 Implement `stopDrawing()` - handle mouseup/touchend/mouseleave, close path, send complete stroke to backend
  * [ ] 2.5 Add color picker event listeners - update `currentColor` when color button clicked, mark active button
  * [ ] 2.6 Add brush size event listeners - update `currentBrushSize` when size button clicked, mark active button
  * [ ] 2.7 Implement `clearCanvas()` - fill white, send clear action to backend if drawer
  * [ ] 2.8 Create `sendStroke()` method - send stroke data (points, color, size) to backend via `sendGameAction('draw_stroke', ...)`
  * [ ] 2.9 Implement `renderStroke()` - receive stroke from WebSocket event, draw on canvas for all players
  * [ ] 2.10 Add touch support - test canvas drawing on mobile devices (iOS, Android)

* [ ] 3.0 **Guess System & Validation**

  Implement guess submission, word normalization, validation, player locking, and feedback (both frontend and backend).

  * [ ] 3.1 Verify `normalizeString()` helper in PictionaryEngine - convert to lowercase, remove accents using iconv, trim whitespace
  * [ ] 3.2 Add event listeners for guess input - handle Enter key press and submit button click
  * [ ] 3.3 Implement `submitGuess()` in PictionaryGameClient - validate non-empty, send to backend via AJAX, clear input on submit
  * [ ] 3.4 Complete backend guess validation in `processGuess()` - check drawer can't guess, check player not locked, normalize and compare
  * [ ] 3.5 Implement correct guess handling - award points to guesser (base + speed bonus), award points to drawer, lock guesser
  * [ ] 3.6 Implement incorrect guess handling - do NOT lock player (allow retry), optionally show in guesses feed
  * [ ] 3.7 Create `handleCorrectGuess()` in client - show lock overlay, disable guess input, display success message with points
  * [ ] 3.8 Implement `addToGuessesFeed()` - prepend guess to feed with player name, highlight correct guesses in green
  * [ ] 3.9 Test guess normalization - verify "CASA", "casa", "Casa", "cása" all match "casa"

* [ ] 4.0 **WebSocket Events & Real-time Synchronization**

  Create custom events (DrawStrokeEvent, WordRevealedEvent, CorrectGuessEvent) and integrate with EventManager for real-time sync.

  * [ ] 4.1 Create `app/Events/Pictionary/DrawStrokeEvent.php` - broadcast stroke data to room channel, include player_id, stroke data
  * [ ] 4.2 Create `app/Events/Pictionary/WordRevealedEvent.php` - private event to drawer only, include word and difficulty
  * [ ] 4.3 Create `app/Events/Pictionary/CorrectGuessEvent.php` - broadcast to all players, include guesser_id, guess, points awarded
  * [ ] 4.4 Create `app/Events/Pictionary/CanvasClearedEvent.php` - broadcast when drawer clears canvas
  * [ ] 4.5 Register all events in `EventServiceProvider` if needed (may auto-discover)
  * [ ] 4.6 Emit `DrawStrokeEvent` in `processDrawStroke()` after saving stroke to game state
  * [ ] 4.7 Emit `WordRevealedEvent` in `startNewRound()` to show word to drawer
  * [ ] 4.8 Emit `CorrectGuessEvent` in `processGuess()` when guess is correct
  * [ ] 4.9 Add event handlers in `PictionaryGameClient` - handleDrawStroke, handleWordRevealed, handleCorrectGuess, handleCanvasCleared
  * [ ] 4.10 Test events with 2 browsers - verify strokes appear in real-time, word only shown to drawer, correct guesses broadcast

* [ ] 5.0 **Game Registration & Capabilities**

  Register Pictionary in the database and create capabilities.json for event configuration. No controller needed - PlayController handles all games.

  * [x] 5.1 ~~Create PictionaryController~~ - NOT NEEDED (PlayController is generic for all games)
  * [x] 5.2 ~~Create routes.php~~ - NOT NEEDED (PlayController handles all API endpoints via apiProcessAction)
  * [x] 5.3 Create `games/pictionary/capabilities.json` - Define event_config with custom events (DrawStroke, WordRevealed, CorrectGuess)
  * [x] 5.4 Register Pictionary in `games` table - Successfully registered using `php artisan games:discover --register`
  * [x] 5.5 Verify view loading - GameServiceProvider correctly auto-discovers `pictionary::game` view from games/pictionary/views/
  * [ ] 5.6 Test route access - Visit `/play/{roomCode}` with a Pictionary room, verify view loads (REQUIRES functional Engine)
  * [ ] 5.7 Test API endpoint - Submit action via `/api/rooms/{code}/action` using PlayController::apiProcessAction() (REQUIRES functional Engine)
  * [x] 5.8 Verify event configuration - Successfully merged 12 base events + 4 Pictionary events = 16 total

* [ ] 6.0 **Game State Management & UI Transitions**

  Implement client-side state management for different phases (waiting, playing, round results, finished) and role-based UI (drawer vs guesser).

  * [ ] 6.1 Implement `handleRoundStarted()` in PictionaryGameClient - parse round_number, drawer_id, word (if drawer)
  * [ ] 6.2 Show word display section ONLY if current player is drawer - update `#word-text` element
  * [ ] 6.3 Show canvas tools ONLY if current player is drawer - toggle `#canvas-tools` visibility
  * [ ] 6.4 Show guess input section ONLY if current player is guesser - toggle `#guess-section` visibility
  * [ ] 6.5 Update drawer info section - display current drawer's name for all players
  * [ ] 6.6 Update round info component - set current round number and total rounds
  * [ ] 6.7 Clear canvas at start of each round - call `clearCanvas()` to reset drawing surface
  * [ ] 6.8 Reset player lock state - hide `#locked-overlay`, enable guess input, set `isLocked = false`
  * [ ] 6.9 Implement `handleRoundEnded()` - show correct word to all players, display round results (who guessed, points)
  * [ ] 6.10 Implement `handleGameFinished()` - hide playing state, show finished state with final results screen
  * [ ] 6.11 Test UI transitions - verify smooth transitions between waiting → playing → round end → next round → game end

* [ ] 7.0 **Testing & Edge Cases**

  Write feature and unit tests, handle edge cases (drawer disconnect, all guessers leave, timer expiration), and test with 2-10 players.

  * [ ] 7.1 Create `tests/Feature/Games/PictionaryGameFlowTest.php` - test full flow from initialize to game finish
  * [ ] 7.2 Test initialize() - verify words loaded, drawer rotation created, player state manager initialized
  * [ ] 7.3 Test onGameStart() - verify first drawer assigned, first round started, roles assigned correctly
  * [ ] 7.4 Test processGuess() with correct answer - verify points awarded, player locked, drawer gets points
  * [ ] 7.5 Test processGuess() with incorrect answer - verify player NOT locked, can guess again
  * [ ] 7.6 Create `tests/Feature/Games/PictionaryGuessTest.php` - test guess normalization and validation
  * [ ] 7.7 Test normalizeString() - verify "CASA" === "casa" === "cása" === " Casa "
  * [ ] 7.8 Create `tests/Feature/Games/PictionaryDrawerRotationTest.php` - test all players get equal turns
  * [ ] 7.9 Test drawer rotation with 3 players over 9 rounds - verify each draws 3 times
  * [ ] 7.10 Create `tests/Unit/PictionaryScoreCalculatorTest.php` - test correct_guess and drawer_success calculations
  * [ ] 7.11 Test speed bonus calculation - verify < 10s = +5, 10-20s = +3, 20-30s = +1, > 30s = +0
  * [ ] 7.12 Test drawer disconnect edge case - verify game auto-skips to next round without errors
  * [ ] 7.13 Test all guessers disconnect - verify game pauses or handles gracefully
  * [ ] 7.14 Test timer expiration - verify round ends, drawer gets no points, advances to next round
  * [ ] 7.15 Manual test with 2 players - one draws, one guesses, verify full gameplay
  * [ ] 7.16 Manual test with 4 players - verify drawer rotation, multiple guessers, correct scoring
  * [ ] 7.17 Manual test with 8-10 players - verify performance, canvas sync, no lag

* [ ] 8.0 **Polish & Optimization**

  Add animations, optimize canvas performance, batch stroke events, improve UX feedback, and ensure mobile compatibility.

  * [ ] 8.1 Implement stroke batching - collect strokes for 100ms, send batch to reduce WebSocket traffic
  * [ ] 8.2 Add CSS transitions for UI state changes - fade in/out for word display, guess overlay, results
  * [ ] 8.3 Add success animation when player guesses correctly - confetti, checkmark, or celebratory effect
  * [ ] 8.4 Add visual feedback when drawer draws - cursor trail, stroke preview, smooth lines
  * [ ] 8.5 Optimize canvas rendering - use requestAnimationFrame for smooth drawing, reduce redraws
  * [ ] 8.6 Test canvas on mobile (iOS Safari, Chrome Android) - verify touch drawing works smoothly
  * [ ] 8.7 Add responsive design - ensure canvas scales properly on different screen sizes
  * [ ] 8.8 Add sound effects (optional) - drawing sound, correct guess sound, round end sound
  * [ ] 8.9 Add loading states - show spinner while waiting for game state, strokes, or guesses
  * [ ] 8.10 Performance test with 10 simultaneous players - monitor WebSocket traffic, canvas render time, latency
  * [ ] 8.11 Add error handling - gracefully handle network errors, failed API calls, disconnections
  * [ ] 8.12 Add accessibility features - keyboard navigation for guess input, ARIA labels, screen reader support

---

## Notes

- **Modules Used:** The engine leverages existing modules (RoundManager, TurnManager, ScoreManager, PlayerStateManager, TimerService, RolesSystem) which are already implemented and tested in Trivia.
- **Reference Implementation:** `games/trivia/` provides working examples of module integration, WebSocket events, and game flow.
- **Canvas Performance:** For 10 simultaneous players, consider batching stroke events (e.g., every 100ms) to reduce WebSocket traffic.
- **Word Normalization:** Guess validation must ignore case, accents, and extra whitespace to improve UX.
- **Drawer Disconnect:** If current drawer disconnects, auto-skip to next round to keep game flowing.

---

---

## 🚨 IMPORTANTE - Convenciones y Buenas Prácticas

**ANTES de empezar cualquier tarea, lee y sigue estas reglas:**

### 1. Consulta la Documentación
- **SIEMPRE** lee `docs/CONVENTIONS.md` antes de empezar
- Sigue las convenciones de nombres, estructura, y patrones
- Consulta `docs/GAME_MODULES_REFERENCE.md` para usar módulos correctamente

### 2. Usa Módulos Existentes - NO Dupliques Código
- ✅ **USA**: `RoundManager`, `TurnManager`, `ScoreManager`, `PlayerStateManager`, `TimerService`, `RolesSystem`
- ❌ **NO REIMPLEMENTES**: Lógica de rondas, turnos, puntuación, locks, timer, roles
- Los módulos ya están probados y funcionan - simplemente úsalos como en Trivia

### 3. Trivia es tu Referencia
- `games/trivia/` es la implementación de referencia **completa y correcta**
- Cuando tengas dudas de cómo hacer algo, mira cómo lo hace Trivia
- **Copia patrones de Trivia**, no inventes nuevos patrones

### 4. Reutiliza, No Dupliques
- Si Trivia tiene un patrón (ej: normalizeString, handleRoundStarted), **cópialo y adáptalo**
- Si ves código similar en 2+ lugares, extráelo a un helper/trait
- Usa componentes Blade existentes: `<x-game.loading-state>`, `<x-game.round-info>`, etc.

### 5. Tests Son Contratos
- Los tests definen el comportamiento esperado
- NO modificar tests sin aprobación
- Todos los tests deben pasar antes de commit

### 6. Eventos y WebSockets
- Sigue el patrón de eventos de Trivia (ej: `QuestionDisplayedEvent`, `AnswerSubmittedEvent`)
- Usa Broadcasting con `PrivateChannel("room.{roomCode}")`
- Emite eventos en el Engine, escucha en el GameClient

### 7. Workflow de Desarrollo
```bash
# 1. Leer convenciones
cat docs/CONVENTIONS.md

# 2. Mirar referencia (Trivia)
cat games/trivia/TriviaEngine.php

# 3. Implementar
# ... code ...

# 4. Ejecutar tests frecuentemente
./test-clean.sh

# 5. NO commitear si tests fallan
```

---

## 📋 Orden Recomendado de Implementación

1. **Empezar con Task 5.0** (Controller & Routes) - necesario para probar el juego
2. **Luego Task 1.0** (Backend Engine) - lógica core del juego
3. **Luego Task 4.0** (WebSocket Events) - comunicación en tiempo real
4. **Luego Task 2.0** (Canvas System) - dibujo frontend
5. **Luego Task 3.0** (Guess System) - adivinanzas frontend
6. **Luego Task 6.0** (UI State Management) - flujo de pantallas
7. **Luego Task 7.0** (Testing) - validar todo funciona
8. **Finalmente Task 8.0** (Polish) - mejoras y optimizaciones

---

## 🎯 Próximos Pasos

Para empezar a implementar las tareas:

```bash
/process-task-list tasks/tasks-game-pictionary.md
```

Esto te guiará paso a paso por cada sub-tarea.
