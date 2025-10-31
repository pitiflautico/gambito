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

### BaseGameEngine

The `BaseGameEngine` provides automatic role rotation:

```php
protected function rotateRole(GameMatch $match, string $roleName): ?int
```
- Extracts roles config from game_state
- Calls PlayerManager's rotateRole()
- Handles persistence and logging

```php
protected function autoRotateRolesOnRoundStart(GameMatch $match, int $currentRound): array
```
- **Called AUTOMATICALLY** during `handleNewRound()` (section 2.2)
- Executed AFTER players are reset and BEFORE `onRoundStarting()` hook
- Iterates through roles with `rotate_on_round_start: true`
- Calls `rotateRole()` for each
- **No manual implementation needed** - built into the framework

#### Automatic Rotation Flow in handleNewRound():
1. Advance round (RoundManager)
2. Reset players (unlock, clear actions)
3. **→ Auto-rotate roles** ← (NEW - automatic)
4. onRoundStarting() hook (custom game logic)
5. Emit RoundStartedEvent
6. onRoundStarted() hook (custom game logic)

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

## Testing

**Sequential mode test** (Mockup game):
1. Create room with 3+ players
2. Start game
3. Check roles displayed for each player (asker vs guesser)
4. Complete round (good/bad answer)
5. Verify role rotates to next player
6. Previous asker becomes guesser
7. Repeat for multiple rounds

**Single role test** (Future game):
1. Create game with single role config
2. Complete multiple rounds
3. Verify all players keep same role
4. Check logs for "No rotation needed" message
