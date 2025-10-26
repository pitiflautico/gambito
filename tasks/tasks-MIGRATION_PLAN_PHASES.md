# Tasks: Migration Plan - Base Engine + Base Client

**Source:** `docs/MIGRATION_PLAN_PHASES.md`

**Goal:** Migrate from HTTP POST + MySQL to WebSocket + Redis incrementally without breaking existing functionality

**Estimated Duration:** 16-21 hours (~2-3 days)

---

## Relevant Files

### Backend (PHP)
- `app/Contracts/BaseGameEngine.php` - Base engine class that all games extend; will receive new helper methods for player caching, Redis state, and WebSocket handling
- `games/trivia/TriviaEngine.php` - Trivia game engine; will be refactored to use BaseGameEngine helpers and eliminate MySQL queries during gameplay
- `app/Events/Game/PlayerActionEvent.php` - New event for broadcasting player action results via WebSocket
- `app/Listeners/HandleClientGameAction.php` - New listener to process incoming WebSocket whispers from clients
- `routes/channels.php` - Broadcasting channel definitions; will be updated to register WebSocket whisper handlers
- `app/Models/GameMatch.php` - Game match model; may need adjustments for Redis integration

### Frontend (JavaScript)
- `resources/js/core/BaseGameClient.js` - Base client class; will receive new methods for WebSocket actions, optimistic updates, and reconnection handling
- `resources/js/games/TriviaGameClient.js` - New Trivia-specific client class extending BaseGameClient
- `games/trivia/views/game.blade.php` - Trivia game view; will be refactored to use Blade components and WebSocket-based client

### Blade Components (New)
- `resources/views/components/game/loading-state.blade.php` - Reusable loading state component with emoji and message
- `resources/views/components/game/countdown.blade.php` - Reusable countdown component with configurable size
- `resources/views/components/game/message.blade.php` - Reusable message component for info/success/error/warning
- `resources/views/components/game/round-info.blade.php` - Reusable round information display
- `resources/views/components/game/player-lock.blade.php` - Reusable player locked state indicator

### Testing
- Manual testing checklist for each phase (no automated tests specified in PRD)
- Browser console testing for WebSocket functionality
- Redis CLI verification for state persistence

---

## Tasks

* [ ] 1.0 Phase 1: Refactor Reusable Code (Backend & Frontend helpers)
  * [ ] 1.1 Add `addScore()` helper method to `BaseGameEngine` with logging and reason parameter
  * [ ] 1.2 Add `cachePlayersInState()` method to `BaseGameEngine` to store player data in `game_state['_config']['players']` during initialization (eliminates queries during gameplay)
  * [ ] 1.3 Add `getPlayerFromState()` method to `BaseGameEngine` to retrieve Player object from cached data in game_state (no database query)
  * [ ] 1.4 Add `broadcastToRoom()` helper method to `BaseGameEngine` for optimized broadcasting
  * [ ] 1.5 Update `TriviaEngine::initialize()` to call `cachePlayersInState()` after loading questions
  * [ ] 1.6 Update `TriviaEngine::processRoundAction()` to use `getPlayerFromState()` instead of querying players from match relationship
  * [ ] 1.7 Update `TriviaEngine` to use `addScore()` from BaseGameEngine with appropriate reason strings (e.g., 'correct_answer')
  * [ ] 1.8 Add `hideElement(id)` and `showElement(id)` helper methods to `BaseGameClient.js`
  * [ ] 1.9 Add `showMessage(message, type)` method to `BaseGameClient.js` for displaying user feedback
  * [ ] 1.10 Test Phase 1: Play complete Trivia game with 4 players, verify no extra queries during gameplay (only 1 initial SELECT players query), check logs for score additions, verify game functions identically to before

* [ ] 2.0 Phase 2: Create Reusable Blade Components
  * [ ] 2.1 Create `resources/views/components/game/loading-state.blade.php` component with props: emoji, message, roomCode
  * [ ] 2.2 Create `resources/views/components/game/countdown.blade.php` component with props: seconds, message, size (small/medium/large)
  * [ ] 2.3 Create `resources/views/components/game/message.blade.php` component with props: type (info/success/error/warning), message, icon, dismissible
  * [ ] 2.4 Create `resources/views/components/game/round-info.blade.php` component with props: current, total, label
  * [ ] 2.5 Create `resources/views/components/game/player-lock.blade.php` component with props: message, icon
  * [ ] 2.6 Refactor `games/trivia/views/game.blade.php` to replace hardcoded loading state HTML with `<x-game.loading-state>` component
  * [ ] 2.7 Refactor `games/trivia/views/game.blade.php` to replace round info HTML with `<x-game.round-info>` component
  * [ ] 2.8 Test Phase 2: Verify all Blade components render correctly, test customization by changing props (emoji, message, size), ensure Trivia game view looks identical or better than before

* [ ] 3.0 Phase 3: Implement WebSocket Bidirectional Communication (Backend)
  * [ ] 3.1 Create `app/Events/Game/PlayerActionEvent.php` event implementing ShouldBroadcast, broadcasting to `room.{code}` channel as `game.action.result`, including player_id, action, success, data, timestamp
  * [ ] 3.2 Create `app/Listeners/HandleClientGameAction.php` listener to process incoming WebSocket whispers from clients
  * [ ] 3.3 Implement `HandleClientGameAction::handle()` to extract action/data/playerId from whisper event, lookup match (from MySQL for now), get engine, call `processAction()`, and broadcast PlayerActionEvent with results
  * [ ] 3.4 Update `routes/channels.php` Presence Channel authorization to register `HandleClientGameAction` as whisper handler for 'game.action' events
  * [ ] 3.5 Ensure `getPlayerFromState()` method exists in BaseGameEngine (should already be added in Phase 1, verify it's being used in HandleClientGameAction)
  * [ ] 3.6 Test Phase 3: Start Reverb server (`php artisan reverb:start`), test WebSocket whisper from browser console with `channel.whisper('game.action', {...})`, verify server logs show action received/processed, verify PlayerActionEvent broadcast, confirm HTTP POST flow still works (backward compatible)

* [ ] 4.0 Phase 4: Implement WebSocket Bidirectional Communication (Frontend)
  * [ ] 4.1 Add `sendGameAction(action, data, optimistic)` method to `BaseGameClient.js` that whispers to WebSocket channel and returns promise with confirmation
  * [ ] 4.2 Add `applyOptimisticUpdate(action, data)` stub method to `BaseGameClient.js` (to be overridden by game-specific clients)
  * [ ] 4.3 Add `revertOptimisticUpdate(action, data)` stub method to `BaseGameClient.js` (to be overridden by game-specific clients)
  * [ ] 4.4 Create `resources/js/games/TriviaGameClient.js` extending `BaseGameClient` with Trivia-specific logic
  * [ ] 4.5 Implement `TriviaGameClient::displayQuestion()` method using BaseGameClient helpers (hideElement, showElement) to render questions
  * [ ] 4.6 Implement `TriviaGameClient::renderOptions()` to create answer buttons and attach click handlers
  * [ ] 4.7 Implement `TriviaGameClient::submitAnswer()` to call `sendGameAction('answer', {answer_index}, true)` with optimistic updates
  * [ ] 4.8 Implement `TriviaGameClient::applyOptimisticUpdate()` to disable all answer buttons when answer is submitted
  * [ ] 4.9 Implement `TriviaGameClient::revertOptimisticUpdate()` to re-enable buttons if submission fails
  * [ ] 4.10 Override `TriviaGameClient::handleRoundStarted()` to extract question from event and call displayQuestion()
  * [ ] 4.11 Update `games/trivia/views/game.blade.php` to import TriviaGameClient, instantiate it, and call setupEventManager() (replace inline JavaScript with module script)
  * [ ] 4.12 Test Phase 4: Play with 4 browsers, verify NO HTTP POST requests in Network tab (only WebSocket), verify optimistic updates (buttons disable immediately), verify correct/incorrect answers display properly, measure latency improvement (should be ~10-20ms vs 100-200ms), test error handling by simulating WebSocket disconnect

* [ ] 5.0 Phase 5: Integrate Redis State Manager
  * [ ] 5.1 Add `loadStateFromRedis(matchId)` method to `BaseGameEngine` to retrieve game_state from Redis using key `game:match:{id}:state`
  * [ ] 5.2 Add `saveStateToRedis(matchId, state, ttl)` method to `BaseGameEngine` to persist game_state to Redis with default 1-hour TTL
  * [ ] 5.3 Add `loadMatch(matchId)` method to `BaseGameEngine` that tries Redis first, falls back to MySQL, and caches to Redis on MySQL hit
  * [ ] 5.4 Add `saveMatch(match, syncToMySQL)` method to `BaseGameEngine` that always saves to Redis, optionally syncs to MySQL for checkpoints
  * [ ] 5.5 Add `syncStateToMySQL(match)` method to `BaseGameEngine` for manual checkpoint synchronization
  * [ ] 5.6 Update `TriviaEngine::processRoundAction()` to call `saveMatch($match)` instead of `$match->save()`, add checkpoint sync every 5 rounds using modulo check
  * [ ] 5.7 Update `HandleClientGameAction::handle()` to cache room code → match ID mapping in Redis (`room:{code}:match_id`), use `loadMatch()` instead of Eloquent query
  * [ ] 5.8 Update `TriviaEngine::initialize()` to save initial state to Redis after caching players
  * [ ] 5.9 Test Phase 5: Clear Redis with `redis-cli FLUSHDB`, start game, verify state appears in Redis with `redis-cli KEYS "game:match:*"`, inspect state with `redis-cli GET "game:match:123:state" | jq`, play complete game, verify 0 MySQL queries during rounds 1-4, verify checkpoint query at round 5, verify final save query at game end, confirm game functions identically with Redis backing
