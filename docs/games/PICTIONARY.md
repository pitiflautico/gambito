# Pictionary

**Estado:** ✅ Completado (MVP Funcional)
**Versión:** 1.0
**Autor:** Gambito
**Tipo:** Drawing
**Jugadores:** 2-10
**Duración:** 15-30 minutos (configurable)

---

## Descripción

Dibuja y adivina palabras antes que los demás. Un jugador dibuja mientras el resto intenta adivinar la palabra secreta.

**Modelo de juego:** Presencial - Los jugadores están físicamente juntos, hablan las respuestas en voz alta. Cada uno usa su dispositivo móvil como interfaz.

---

## Cómo Funciona

### Flujo del Juego

1. **Lobby**: Los jugadores se unen a la sala mediante código QR o código de sala
2. **Inicio**: El master inicia la partida, se genera orden de turnos aleatorio
3. **Turno de Dibujo**:
   - Un jugador recibe la palabra secreta (dibujante)
   - Tiene 90 segundos para dibujarla en el canvas
   - Los demás jugadores ven el dibujo en tiempo real
4. **Intento de Respuesta** (Presencial):
   - Jugador hace clic en "¡YA LO SÉ!" cuando cree saber la respuesta
   - Dice la respuesta en VOZ ALTA (juego presencial)
   - El dibujante escucha y confirma si es correcta o incorrecta
   - Si es correcta: Se otorgan puntos y termina el turno
   - Si es incorrecta: El jugador queda eliminado de esta ronda
5. **Siguiente Turno**: Siguiente jugador en el orden se convierte en dibujante
6. **Final**: Después de completar todas las rondas, el jugador con más puntos gana (por defecto: 1 ronda por jugador)

### Roles

- **Dibujante** (1 jugador por turno):
  - Ve la palabra secreta en su pantalla
  - Dibuja en el canvas con herramientas de dibujo
  - Escucha respuestas verbales y confirma si son correctas/incorrectas
  - Puede ver quién presionó "¡YA LO SÉ!"

- **Adivinadores** (resto de jugadores):
  - Ven el canvas en tiempo real (WebSocket)
  - NO ven la palabra secreta
  - Presionan "¡YA LO SÉ!" cuando sepan la respuesta
  - Dicen la respuesta en voz alta
  - Si fueron eliminados, esperan al siguiente turno

---

## Características Implementadas

### ✅ Sistema de Turnos (TurnManager Module)
- **Módulo:** `TurnSystem` (ver `docs/modules/optional/TURN_SYSTEM.md`)
- Modo secuencial (cada jugador dibuja en orden)
- Rotación automática entre jugadores
- Rondas dinámicas basadas en número de jugadores (1 ronda por jugador)
- Detección automática de fin de partida
- Configurable: rondas automáticas o personalizadas (1-10)

### ✅ Sistema de Puntuación
- Puntos basados en velocidad de respuesta:
  - 0-30 segundos: 100 puntos (adivinador), 50 puntos (dibujante)
  - 30-60 segundos: 75 puntos (adivinador), 40 puntos (dibujante)
  - 60-90 segundos: 50 puntos (adivinador), 25 puntos (dibujante)
- Ranking en tiempo real
- Resultados finales con ganador

### ✅ Canvas de Dibujo
- Herramientas: Lápiz, Borrador
- Selector de colores (12 colores)
- Selector de grosor (4 tamaños)
- Botón limpiar canvas
- Sincronización en tiempo real vía WebSocket

### ✅ WebSocket (Laravel Reverb)
- Sincronización de trazos del canvas
- Eventos de jugadores (respuesta, eliminación)
- Cambios de turno en tiempo real
- Actualizaciones de puntuación
- Fin de ronda y fin de juego

### ✅ Sistema de Invitados
- Jugadores sin registro pueden unirse
- Identificación por nombre personalizado
- Sesión persistente durante la partida
- Página de agradecimiento al finalizar

### ✅ Interfaz Adaptativa
- Vista diferente para dibujante vs adivinadores
- Cambio automático de rol al cambiar turno
- Indicador visual del jugador actual
- Lista de jugadores con roles y puntuaciones
- Modales para resultados de ronda y finales

---

## Estructura de Archivos

```
games/pictionary/
├── PictionaryEngine.php          # Motor principal del juego
├── config.json                   # Configuración y settings customizables
├── capabilities.json             # Capacidades del juego
├── words.json                    # Lista de palabras por dificultad
├── Events/                       # Eventos de broadcasting
│   ├── PlayerAnsweredEvent.php
│   ├── PlayerEliminatedEvent.php
│   ├── RoundEndedEvent.php
│   ├── TurnChangedEvent.php
│   ├── GameFinishedEvent.php
│   ├── GameStateUpdatedEvent.php
│   ├── CanvasDrawEvent.php
│   └── AnswerConfirmedEvent.php
└── views/
    └── canvas.blade.php          # Vista principal del juego

public/games/pictionary/css/
└── canvas.css                    # Estilos del juego

resources/js/
└── pictionary-canvas.js          # Lógica del frontend (compilado con Vite)
```

**IMPORTANTE:** El JavaScript DEBE estar en `resources/js/` y compilarse con Vite. NO poner archivos JS en `games/pictionary/js/` (ver GAMES_CONVENTION.md).

---

## Estado del Juego (game_state)

```json
{
  "phase": "playing",              // lobby | playing | scoring | results

  // ===== TURN SYSTEM FIELDS (from TurnManager) =====
  "current_round": 1,              // Ronda actual (1-based)
  "total_rounds": 3,               // Total de rondas (dinámico: 1 por jugador)
  "current_turn_index": 0,         // Índice del turno actual (0-based)
  "turn_order": [48, 49, 47],      // Orden de turnos (IDs de jugadores)

  // ===== PICTIONARY-SPECIFIC FIELDS =====
  "current_drawer_id": 48,         // ID del dibujante actual
  "current_word": "montaña",       // Palabra secreta
  "current_word_difficulty": "medium",
  "game_is_paused": false,         // Si el juego está pausado (esperando confirmación)
  "turn_started_at": "2025-10-21 13:00:00",
  "turn_duration": 90,             // Segundos por turno (configurable: 60/90/120)
  "scores": {                      // Puntuaciones acumuladas
    "47": 125,
    "48": 225,
    "49": 400
  },
  "eliminated_this_round": [47],   // IDs de jugadores eliminados esta ronda
  "pending_answer": {              // Respuesta pendiente de confirmación
    "player_id": 49,
    "player_name": "pepito",
    "timestamp": "2025-10-21 13:05:00"
  },
  "words_used": ["casa", "perro"], // Palabras ya usadas
  "words_available": {             // Palabras disponibles por dificultad
    "easy": [...],
    "medium": [...],
    "hard": [...]
  }
}
```

**Nota:** Los campos `current_round`, `total_rounds`, `current_turn_index`, `turn_order` son gestionados por el módulo `TurnManager`. Ver `docs/modules/optional/TURN_SYSTEM.md`.

---

## Eventos de Broadcasting

### 1. `player.answered`
Se emite cuando un jugador presiona "¡YA LO SÉ!"

```javascript
{
  player_id: 49,
  player_name: "pepito",
  message: "🙋 pepito dice: ¡YA LO SÉ!",
  timestamp: "2025-10-21T13:05:00.000000Z"
}
```

### 2. `player.eliminated`
Se emite cuando un jugador es eliminado por respuesta incorrecta

```javascript
{
  player_id: 47,
  player_name: "Admin",
  message: "❌ Admin fue eliminado",
  timestamp: "2025-10-21T13:05:15.000000Z"
}
```

### 3. `round.ended`
Se emite cuando termina una ronda (alguien acertó)

```javascript
{
  round: 1,
  word: "montaña",
  winner_id: 49,
  winner_name: "pepito",
  guesser_points: 100,
  drawer_points: 50,
  scores: { "47": 0, "48": 50, "49": 100 },
  timestamp: "2025-10-21T13:06:00.000000Z"
}
```

### 4. `turn.changed`
Se emite cuando cambia el turno (nuevo dibujante)

```javascript
{
  new_drawer_id: 48,
  new_drawer_name: "pepino",
  round: 2,
  turn: 1,
  scores: { "47": 125, "48": 225, "49": 400 },
  timestamp: "2025-10-21T13:06:30.000000Z"
}
```

### 5. `game.finished`
Se emite cuando el juego termina (todas las rondas completadas)

```javascript
{
  winner_id: 49,
  winner_name: "pepito",
  final_scores: { "47": 125, "48": 225, "49": 400 },
  ranking: [
    { player_id: 49, player_name: "pepito", score: 400 },
    { player_id: 48, player_name: "pepino", score: 225 },
    { player_id: 47, player_name: "Admin", score: 125 }
  ],
  timestamp: "2025-10-21T13:30:00.000000Z"
}
```

### 6. `game.state.updated`
Se emite para actualizaciones generales del estado

```javascript
{
  update_type: "turn_change" | "phase_change" | "round_ended",
  phase: "playing",
  round: 2,
  rounds_total: 5,
  scores: {...},
  eliminated_this_round: [47],
  current_drawer_id: 48,
  timestamp: "2025-10-21T13:06:30.000000Z"
}
```

### 7. `canvas.draw`
Se emite para sincronizar trazos del canvas

```javascript
{
  action: "draw" | "clear",
  stroke: {
    x0: 100,
    y0: 150,
    x1: 120,
    y1: 180,
    color: "#FF0000",
    size: 5
  }
}
```

---

## API Endpoints

### POST `/api/pictionary/player-answered`
Jugador presiona "¡YA LO SÉ!"

**Request:**
```json
{
  "room_code": "QAHGBH",
  "match_id": 9,
  "player_id": 49,
  "player_name": "pepito"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Player answered successfully"
}
```

### POST `/api/pictionary/confirm-answer`
Dibujante confirma si la respuesta es correcta o incorrecta

**Request:**
```json
{
  "room_code": "QAHGBH",
  "match_id": 9,
  "drawer_id": 48,
  "guesser_id": 49,
  "is_correct": true
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "correct": true,
    "round_ended": true,
    "guesser_points": 100,
    "drawer_points": 50,
    "seconds_elapsed": 25,
    "message": "¡pepito acertó!",
    "phase": "scoring"
  }
}
```

### POST `/api/pictionary/advance-phase`
Avanzar a la siguiente fase (llamado al hacer clic en "Siguiente ronda")

**Request:**
```json
{
  "room_code": "QAHGBH",
  "match_id": 9
}
```

**Response:**
```json
{
  "success": true,
  "message": "Phase advanced successfully"
}
```

### POST `/api/pictionary/get-word`
Obtener palabra secreta (solo para dibujante)

**Request:**
```json
{
  "match_id": 9,
  "player_id": 48
}
```

**Response:**
```json
{
  "success": true,
  "word": "montaña"
}
```

### POST `/api/pictionary/draw`
Sincronizar trazo del canvas

**Request:**
```json
{
  "room_code": "QAHGBH",
  "match_id": 9,
  "stroke": {
    "x0": 100, "y0": 150,
    "x1": 120, "y1": 180,
    "color": "#FF0000",
    "size": 5
  }
}
```

### POST `/api/pictionary/clear`
Limpiar canvas

**Request:**
```json
{
  "room_code": "QAHGBH",
  "match_id": 9
}
```

---

## Fases del Juego

1. **lobby**: Esperando jugadores (no gestionado por PictionaryEngine)
2. **playing**: Jugando - dibujante dibuja, adivinadores intentan adivinar
3. **scoring**: Contando puntos después de que alguien acertó
4. **results**: Juego terminado - mostrando ganador y ranking

---

## Flujo de Finalización de Partida

### Cuando termina la última ronda:

1. **Backend** detecta que `current_round >= total_rounds && current_turn_index >= (player_count - 1)`
2. Cambia `phase` a `'results'`
3. Calcula ganador y ranking
4. Emite evento `game.finished` con resultados completos
5. **Frontend** escucha el evento y muestra modal de resultados finales

**Nota:** La detección de fin de partida se realiza en la fase `'scoring'`, verificando que estamos en la última ronda Y el último turno.

### Resultados según tipo de usuario:

- **Admin/Master**: Botón "Volver al lobby" → Puede iniciar nueva partida
- **Invitados**: Botón "Finalizar" → Redirige a `/thanks` (página de agradecimiento)
- **Usuarios autenticados**: Botón "Volver al lobby" + "Ver otros juegos"

### Si alguien recarga la página después del juego:

- El backend pasa `phase: 'results'` y `gameResults` en `window.gameData`
- El JavaScript detecta esto en `init()` y automáticamente muestra el modal de resultados
- Todos los jugadores ven los resultados sin importar cuándo entren

---

## Configuración del Juego

Pictionary utiliza el sistema de configuración declarativa. Ver `games/pictionary/config.json` y `docs/conventions/GAME_CONFIGURATION_CONVENTION.md`.

### Settings Customizables (al crear sala):

1. **Número de rondas:**
   - Automático (1 ronda por jugador) - Recomendado
   - Personalizado (1-10 rondas)

2. **Duración por turno:**
   - 60 segundos (rápido)
   - 90 segundos (normal) - Default
   - 120 segundos (relajado)

3. **Dificultad de palabras:**
   - Fácil
   - Media
   - Difícil
   - Mixta (todas) - Default

4. **Permitir pistas:** Checkbox (default: false, no implementado aún)

---

## Módulos Utilizados

### Core Modules (Siempre activos):
- `GameEngine` - Motor base del juego
- `RoomManager` - Gestión de salas y matches

### Optional Modules (Activados para Pictionary):
- ✅ `TurnSystem` - Gestión de turnos y rondas (ver `docs/modules/optional/TURN_SYSTEM.md`)
- ✅ `GuestSystem` - Invitados sin registro
- ✅ `ScoringSystem` - Puntuación basada en velocidad
- ⚠️ `TimerSystem` - Temporizadores (implementación parcial)
- 🚧 `RolesSystem` - Roles (dibujante/adivinador) - implementado ad-hoc, pendiente extracción

---

## Mejoras Futuras (Fase 4 - Modularización)

- [x] ✅ Extraer Turn System como módulo
- [x] ✅ Sistema de configuración declarativa
- [x] ✅ Dificultades de palabras seleccionables
- [ ] Extraer Scoring System como módulo
- [ ] Extraer Timer System como módulo completo
- [ ] Extraer Roles System como módulo
- [ ] Implementar sistema de hints (revelar letras)
- [ ] Añadir categorías de palabras
- [ ] Modo equipos
- [ ] Replay de partidas
- [ ] Espectadores

---

## Notas de Implementación

### Convención Presencial
Este juego está diseñado para **jugarse en persona**:
- Los jugadores hablan las respuestas en voz alta
- No hay input de texto para respuestas
- El dibujante escucha y confirma manualmente
- Fomenta la interacción social cara a cara

### WebSockets con Laravel Reverb
- Configurado en `config/broadcasting.php`
- Timeout de 2 segundos para evitar bloqueos
- Canal público: `room.{code}` (TODO: cambiar a private en producción)
- Echo inicializado en `resources/js/bootstrap.js`

### Gestión de Sesiones
- Usuarios autenticados: `user_id`
- Invitados: `session_id` único
- Constraint: UNIQUE(match_id, session_id) - permite guest en múltiples partidas
- Limpieza automática al cambiar de sala

### Protección contra Doble Click
- Flag `isConfirming` en JavaScript
- Botones deshabilitados durante petición
- Previene errores "No hay respuesta pendiente"

---

**Última actualización:** 21 de octubre de 2025
**Versión documentación:** 1.1 - Integración Turn System Module
