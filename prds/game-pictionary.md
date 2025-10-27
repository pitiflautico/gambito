# Pictionary - Product Requirements Document

## Overview
**Game Type**: Creatividad - Dibujo y adivinanzas
**Description**: Juego clásico de Pictionary donde un jugador dibuja mientras los demás adivinan. Soporta modo individual y por equipos.
**Target Audience**: Grupos de 2-10 jugadores, ideal para reuniones presenciales

## Core Gameplay

### Players
- **Min Players**: 2
- **Max Players**: 10
- **Guest Support**: Yes - Permite invitados sin registro

### Teams
- **Mode**: Ambos (Individual/Teams) - Configurable por el host
- **Min Teams**: 2 (cuando se juega por equipos)
- **Max Teams**: 4 (cuando se juega por equipos)

### Game Structure
- **Rounds**: Configurable (el host decide cuántas rondas, default: 10)
- **Turn Mode**: Secuencial - Un jugador dibuja a la vez

### Timing
- **Timer Type**: Por turno (cada dibujante tiene su tiempo)
- **Duration**: 30 segundos por turno
- **On Expiration**: Se pasa al siguiente turno (nadie gana puntos)

### Roles
- **drawer**: El jugador que dibuja (1 por turno)
- **guesser**: Los jugadores que adivinan (todos los demás)
- **viewer**: Espectadores que solo observan (opcional)

**Nota**: Los roles rotan cada ronda. Cada jugador tendrá la oportunidad de ser drawer.

### Scoring
- **Point System**:
  - **Puntos por adivinar**: +10 puntos al jugador que adivina correctamente
  - **Puntos al dibujante**: +5 puntos al drawer si alguien adivina
  - **Speed Bonus**: +0 a +5 puntos extra según qué tan rápido se adivine
    - Primeros 10s: +5 bonus
    - 10-20s: +3 bonus
    - 20-30s: +1 bonus
  - **Puntos por orden**: El primero en adivinar gana más puntos que los siguientes

### Special Features
- **Player Locks**: Los jugadores se bloquean tras adivinar correctamente (no pueden adivinar de nuevo en esa ronda)
- **Canvas**: Sistema de dibujo en tiempo real sincronizado entre todos los jugadores
- **Word Bank**: Banco de palabras categorizadas (fácil, medio, difícil)

## Technical Architecture

### Modules Enabled

**Core (siempre activos)**:
- `game_core` - Ciclo de vida del juego
- `room_manager` - Gestión de salas
- `real_time_sync` - WebSockets con Reverb

**Opcionales (configurados)**:
- `guest_system`
  - enabled: true

- `turn_system`
  - enabled: true
  - mode: sequential

- `round_system`
  - enabled: true
  - total_rounds: 10 (configurable)

- `scoring_system`
  - enabled: true
  - calculator: PictionaryScoreCalculator

- `timer_system`
  - enabled: true
  - round_duration: 30 (segundos por turno)

- `teams_system`
  - enabled: true
  - min_teams: 2
  - max_teams: 4
  - allow_self_selection: true

- `player_state_system`
  - enabled: true
  - uses_locks: true (bloqueo tras adivinar)

- `roles_system`
  - enabled: true
  - roles: ['drawer', 'guesser', 'viewer']

### Key Implementation Points

#### Backend
- **Engine**: `games/pictionary/PictionaryEngine.php`
- **Score Calculator**: `games/pictionary/PictionaryScoreCalculator.php`
- **Game State**:
  - Canvas data (strokes, drawings)
  - Current word to draw
  - Who has guessed correctly
  - Drawer rotation tracking

#### Frontend
- **Client**: `games/pictionary/js/PictionaryGameClient.js`
- **UI**: `games/pictionary/views/game.blade.php`
- **Canvas Component**: HTML5 Canvas para dibujo
- **Real-time Events**:
  - DrawStrokeEvent (sincronizar dibujos)
  - GuessSubmittedEvent (intentos de adivinar)
  - CorrectGuessEvent (alguien adivinó)
  - WordRevealedEvent (mostrar palabra al drawer)

### File Structure
```
games/pictionary/
├── PictionaryEngine.php
├── PictionaryScoreCalculator.php
├── config.json
├── words.json (banco de palabras)
├── rules.json
├── views/
│   └── game.blade.php
└── js/
    └── PictionaryGameClient.js
```

## User Experience Flow

### 1. Lobby Phase
- Players join room
- Host configures:
  - Número de rondas (5, 10, 15)
  - Modo de juego (Individual vs Equipos)
  - Si modo equipos: asignación de equipos
  - Dificultad de palabras (fácil, medio, difícil)
- Mínimo 2 jugadores para empezar

### 2. Game Start
- Sistema asigna primer drawer aleatoriamente
- Resto de jugadores son guessers
- Se registra orden de rotación para drawers

### 3. Round Flow

**Inicio de ronda**:
1. Sistema selecciona palabra aleatoria del banco
2. Se revela palabra SOLO al drawer
3. Drawer ve canvas en blanco + herramientas de dibujo
4. Guessers ven canvas en blanco + campo de texto para adivinar
5. Timer de 30s inicia automáticamente

**Durante la ronda**:
- Drawer dibuja → eventos sincronizados en tiempo real a todos
- Guessers escriben respuestas → servidor valida
- Si respuesta correcta:
  - Jugador se bloquea (lock)
  - Gana puntos (base + speed bonus)
  - Drawer gana puntos
  - Se muestra feedback visual
- Si todos adivinan o timer expira → fin de ronda

**Fin de ronda**:
- Mostrar palabra correcta
- Mostrar puntuaciones parciales
- Avanzar al siguiente drawer (rotación)
- Esperar 3 segundos antes de siguiente ronda

### 4. Scoring & Results

**Cálculo de puntos** (ver PictionaryScoreCalculator):
- **Guesser correcto**: 10 puntos base + speed bonus (0-5)
- **Drawer**: 5 puntos si al menos 1 persona adivina
- **Speed bonus**:
  - < 10s: +5
  - 10-20s: +3
  - 20-30s: +1

**Resultados por ronda**:
- Top 3 de la ronda
- Puntuaciones acumuladas

### 5. Game End
- Final scores (ordenados de mayor a menor)
- Ganador individual o equipo ganador
- Estadísticas:
  - Mejor dibujante (más personas adivinaron sus dibujos)
  - Mejor adivinador (más respuestas correctas)
  - Promedio de tiempo de respuesta
- Botón "Jugar de nuevo"

## Development Notes

### Must Implement (TODOs)

**Backend (PictionaryEngine.php)**:
1. `initialize()` - Cargar banco de palabras, configurar rotación de drawers
2. `onGameStart()` - Asignar primer drawer, preparar primera ronda
3. `processRoundAction()` - Validar respuestas de guessers, manejar dibujos
4. `startNewRound()` - Seleccionar palabra, rotar drawer, resetear locks
5. `endCurrentRound()` - Calcular puntos, preparar datos para siguiente ronda
6. `handleDrawStroke()` - Broadcast de strokes del canvas en tiempo real
7. `handleGuess()` - Validar respuesta contra palabra actual

**Frontend (PictionaryGameClient.js)**:
1. `handleRoundStarted()` - Mostrar palabra a drawer, iniciar canvas
2. `handleDrawStroke()` - Renderizar stroke en canvas
3. `submitGuess()` - Enviar intento de adivinar
4. `handleCorrectGuess()` - Feedback visual cuando alguien adivina
5. Canvas drawing tools (pencil, eraser, colors, clear)

**Score Calculator**:
1. `calculate('correct_guess', context)` - Base + speed bonus
2. `calculate('drawer_success', context)` - Puntos al drawer
3. `calculateSpeedBonus(time_taken)` - Bonus según tiempo

### References
- `docs/GAME_MODULES_REFERENCE.md` - Module details
- `docs/TIMER_SYSTEM_INTEGRATION.md` - Timer implementation
- `docs/BASE_ENGINE_CLIENT_DESIGN.md` - Architecture patterns
- `games/trivia/` - Reference implementation for scoring, timer, rounds

## Special Considerations

### Canvas Synchronization
- Use WebSockets para sync en tiempo real
- Batch strokes para reducir eventos (ej: cada 100ms)
- Considerar performance con 10 jugadores simultáneos

### Word Bank
- Categorías: Fácil (50 palabras), Medio (50), Difícil (50)
- Evitar repetición en la misma partida
- Posibilidad de palabras custom del host (futuro)

### Roles Rotation
- Tracking de quién ya dibujó en partida actual
- Asegurar que todos dibujan igual número de veces
- Si jugadores impares, algunos dibujarán 1 vez más

### Teams Mode
- Puntos se acumulan por equipo
- Solo miembros del equipo contrario pueden adivinar (opcional)
- O todos pueden adivinar pero puntos van al equipo (más divertido)

### Edge Cases
- ¿Qué pasa si drawer se desconecta? → Auto-skip a siguiente turno
- ¿Qué pasa si todos los guessers se desconectan? → Pausar juego
- ¿Se puede adivinar escribiendo la palabra exacta? → Sí, ignorar mayúsculas/acentos
- ¿Permitir hints? → No, drawer solo puede dibujar

## Next Steps

1. Ejecutar: `/generate-tasks prds/game-pictionary.md`
2. Ejecutar: `/process-task-list tasks/tasks-pictionary.md`
3. Implementar TODOs fase por fase
4. Probar con 2 jugadores, luego 4, luego 10
5. Refinar canvas drawing experience
6. Agregar animaciones y polish
