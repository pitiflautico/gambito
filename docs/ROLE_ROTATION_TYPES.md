# Role Rotation Types

## Overview

The role rotation system supports different types of role rotation modes that are automatically detected from the game configuration. This allows games to have different role management strategies without code changes.

## Rotation Types

### 1. Sequential Mode

**When to use**: Games where one player has a special role that rotates each round, while other players have a different role.

**Configuration pattern**:
- One "main" role with `count: 1`
- One "rest" role with `count: -1` (all remaining players)
- Main role has `rotate_on_round_start: true`

**Example** (Mockup, Pictionary):
```json
{
  "roles_system": {
    "enabled": true,
    "roles": [
      {
        "name": "asker",
        "count": 1,
        "description": "El jugador que hace las preguntas",
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
}
```

**Behavior**:
- Main role rotates sequentially through players (circular)
- Previous player with main role gets the rest role
- Always maintains exactly 1 player with main role, rest with rest role

**Example flow** (3 players):
- Round 1: Player 1 = asker, Players 2,3 = guessers
- Round 2: Player 2 = asker, Players 1,3 = guessers
- Round 3: Player 3 = asker, Players 1,2 = guessers
- Round 4: Player 1 = asker, Players 2,3 = guessers (cycle repeats)

---

### 2. Single Role Mode

**When to use**: Games where all players have the same role throughout the entire game.

**Configuration pattern**:
- Only one role defined
- All players get the same role

**Example** (Basic games):
```json
{
  "roles_system": {
    "enabled": true,
    "roles": [
      {
        "name": "player",
        "count": -1,
        "description": "Jugador regular",
        "rotate_on_round_start": false
      }
    ]
  }
}
```

**Behavior**:
- No actual rotation happens
- `rotateRole()` returns `null` (no change)
- All players keep their role throughout the game
- Validation is still performed

---

## Architecture

### Initialization (BaseGameEngine)

The `BaseGameEngine::initializeModules()` method initializes roles_system for ALL games:

```php
protected function initializeModules(GameMatch $match, array $moduleOverrides = []): void
```

**Process:**
1. Reads roles from `config.json` → `modules.roles_system.roles`
2. If no roles configured, uses default: `player` (count: -1, no rotation)
3. Creates top-level `game_state['roles_system']` with:
   - `enabled: true` (always)
   - `roles: [...]` (from config or default)
4. This ensures `isModuleEnabled($match, 'roles_system')` returns `true`

**Why this matters:**
- roles_system is **mandatory** for all games
- No need to initialize it in game-specific code
- Framework handles it automatically

---

### PlayerManager

The `PlayerManager` class handles role rotation type detection and execution:

#### Main method
```php
public function rotateRole(string $roleName, array $rolesConfig): ?int
```
- Detects rotation type automatically from config
- Delegates to appropriate handler (`rotateSequentialRole()` or `rotateSingleRole()`)
- Returns new player ID or null if no rotation

#### Type detection
```php
private function detectRotationType(array $rolesConfig): string
```
- Returns `'sequential'` if pattern is: 1 main role (count: 1) + 1 rest role (count: -1)
- Returns `'single'` if only one role is defined
- Can be extended for future rotation types

#### Handlers
```php
private function rotateSequentialRole(string $roleName, ?string $alternativeRole): int
private function rotateSingleRole(string $roleName): ?int
```

**Sequential rotation logic:**
- Gets all player IDs in order
- Finds current player with main role
- Calculates next player (circular: wraps to first after last)
- Removes main role from current player
- Assigns rest role to current player (if defined)
- Assigns main role to next player

**Single rotation logic:**
- Simply returns `null` (no rotation needed)
- Logs info message

---

### BaseGameEngine

The `BaseGameEngine` provides automatic role rotation:

#### Initialization
```php
protected function initializeModules(GameMatch $match, array $moduleOverrides = []): void
```
- **Called once** when game starts
- Initializes `roles_system` at top-level of game_state
- Uses config from `config.json` or default "player" role
- **ALWAYS creates roles_system** (mandatory module)

#### Rotation
```php
protected function rotateRole(GameMatch $match, string $roleName): ?int
```
- Extracts roles config from game_state
- Calls PlayerManager's rotateRole()
- Handles persistence and logging
- Returns new player ID or null

```php
protected function autoRotateRolesOnRoundStart(GameMatch $match, int $currentRound): array
```
- **Called AUTOMATICALLY** during `handleNewRound()` (section 2.2)
- Executed AFTER players are reset and BEFORE `onRoundStarting()` hook
- Iterates through roles with `rotate_on_round_start: true`
- Calls `rotateRole()` for each
- **No manual implementation needed** - built into the framework
- **Only rotates when `advanceRound = true`** (not on reconnection)

#### Automatic Rotation Flow in handleNewRound():
1. Advance round (RoundManager) - only if `advanceRound = true`
2. Reset players (unlock, clear actions)
3. **→ Auto-rotate roles** ← (automatic, only if `advanceRound = true`)
4. onRoundStarting() hook (custom game logic)
5. Emit RoundStartedEvent
6. onRoundStarted() hook (custom game logic)

**When rotation happens:**
- ✅ When advancing to next round (good_answer, bad_answer)
- ❌ When restarting current round (player reconnection)
- ❌ On first round (roles already assigned in initialize)

---

## Adding New Rotation Types

To add a new rotation type:

1. **Define the config pattern** in your game's `config.json`

2. **Update detection logic** in `PlayerManager::detectRotationType()`
```php
private function detectRotationType(array $rolesConfig): string
{
    // ... existing detection logic ...

    // Add your new type detection
    if ($yourCondition) {
        return 'your_type';
    }
}
```

3. **Create handler method** in `PlayerManager`
```php
private function rotateYourTypeRole(string $roleName, ...): ?int
{
    // Your rotation logic
}
```

4. **Add case to switch** in `PlayerManager::rotateRole()`
```php
switch ($rotationType) {
    case 'sequential':
        // ...
    case 'single':
        // ...
    case 'your_type':
        return $this->rotateYourTypeRole($roleName, ...);
}
```

---

## Future Rotation Types (Ideas)

### Random Rotation
- Roles assigned randomly each round
- No player tracking needed

### Team-based Rotation
- Roles rotate within teams, not globally
- Team 1 asker → Team 1 next player

### Skill-based Rotation
- Rotate based on player scores or performance
- Lowest scorer becomes special role

### Time-based Rotation
- Rotate after X minutes instead of after round
- Uses timer system

---

## Complete Flow Summary

### 1. Game Initialization
```
User creates room → Starts game → BaseGameEngine::initialize()
```

**What happens:**
1. `initializeModules()` is called
2. Reads `config.json` → `modules.roles_system.roles`
3. Creates `game_state['roles_system']`:
   ```json
   {
     "enabled": true,
     "roles": [
       {"name": "asker", "count": 1, "rotate_on_round_start": true},
       {"name": "guesser", "count": -1, "rotate_on_round_start": false}
     ]
   }
   ```
4. Game-specific `initialize()` assigns initial roles via PlayerManager
5. PlayerManager assigns roles based on config

**Result:** Player 1 = asker, Players 2-4 = guessers

---

### 2. Round Advances (User clicks "good answer")
```
User action → processRoundAction() → handleNewRound(advanceRound: true)
```

**What happens in handleNewRound():**
1. **Advance round** (RoundManager): `current_round` = 2
2. **Reset players** (PlayerManager): unlock all, clear actions
3. **Auto-rotate roles** (NEW):
   - Check `isModuleEnabled('roles_system')` → true
   - Check `advanceRound` → true
   - Call `autoRotateRolesOnRoundStart(match, 2)`
   - Loop through roles with `rotate_on_round_start: true`
   - Find "asker" → call `rotateRole(match, 'asker')`
   - PlayerManager detects "sequential" type
   - Rotates: Player 1 (asker) → guesser, Player 2 → asker
4. **onRoundStarting()** hook (game-specific prep)
5. **Emit RoundStartedEvent** (broadcast to clients)
6. **onRoundStarted()** hook

**Result:** Player 2 = asker, Players 1,3,4 = guessers

---

### 3. Player Disconnects & Reconnects
```
Player refreshes browser → onPlayerReconnected() → handleNewRound(advanceRound: false)
```

**What happens:**
1. **Lock acquired** (prevents race conditions)
2. Game unpaused
3. **Restart current round** without advancing:
   - `handleNewRound(advanceRound: false)`
   - Round number stays same (still round 2)
   - Players reset (unlock, clear actions)
   - **Roles NOT rotated** (advanceRound = false)
4. **Lock released**

**Result:** Roles unchanged - Player 2 still asker

---

### 4. Frontend Display Update
```
RoundStartedEvent → MockupGameClient.handleRoundStarted()
```

**What happens:**
1. Event received via WebSocket
2. `gameState` updated from server
3. `updateRoundCounter()` called
4. `updateRoleDisplay()` called (NEW)
   - Reads `gameState.player_system.players[playerId].persistentRoles`
   - Updates DOM to show current role
5. Buttons re-enabled

**Result:** UI shows correct role for each player

---

## Testing

**Sequential mode test** (Mockup game):
1. Create room with 3+ players
2. Start game
3. Check roles displayed for each player (asker vs guesser)
4. Complete round (good/bad answer)
5. Verify role rotates to next player
6. Previous asker becomes guesser
7. Refresh browser → role should NOT change
8. Complete another round → role should rotate again

**Single role test** (Future game):
1. Create game with single role config
2. Complete multiple rounds
3. Verify all players keep same role
4. Check logs for "No rotation needed" message

**Reconnection test** (Critical):
1. Start game with roles assigned
2. Note current role
3. Refresh browser (disconnect + reconnect)
4. Verify role did NOT change
5. Complete round
6. Verify role DID rotate to next player
