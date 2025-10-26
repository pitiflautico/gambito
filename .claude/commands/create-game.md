---
description: Asistente interactivo para crear nuevos juegos siguiendo la arquitectura modular
---

# Comando: Crear Nuevo Juego

Eres un asistente experto en la arquitectura de este proyecto. Tu objetivo es crear un nuevo juego de forma **guiada, interactiva y sin duplicar código**.

## Documentación de Referencia

ANTES de empezar, DEBES leer estos documentos:

1. **OBLIGATORIO - Leer primero**:
   - `docs/CREATE_GAME_GUIDE.md` - Guía completa con 12 preguntas y templates

2. **Consultar según necesidad**:
   - `docs/GAME_MODULES_REFERENCE.md` - Detalles técnicos de módulos
   - `docs/TIMER_SYSTEM_INTEGRATION.md` - Si usa timer
   - `docs/CONVENTIONS.md` - Validar convenciones
   - `docs/BASE_ENGINE_CLIENT_DESIGN.md` - Arquitectura base

## Flujo del Comando

### Paso 1: Hacer las 12 Preguntas (Según CREATE_GAME_GUIDE.md)

Usa la herramienta `AskUserQuestion` para hacer estas preguntas de forma interactiva:

**Pregunta 1: Información Básica**
- ¿Cómo se llama tu juego?
- Validar que no exista en `games/`
- Convertir a slug (ej: "Speed Quiz" → "speed-quiz")

**Pregunta 2: Descripción**
- Describe brevemente el juego (1-2 líneas)

**Pregunta 3: Tipo de Juego**
- Opciones:
  - Preguntas y Respuestas (trivia, quiz)
  - Creatividad (pictionary, palabra secreta)
  - Cartas (uno, poker)
  - Estrategia por turnos (ajedrez, damas)
  - Tiempo real (speed challenges)
  - Otro

**Pregunta 4: Jugadores**
- Min jugadores (mínimo 2)
- Max jugadores (recomendado ≤ 10)
- ¿Permite invitados sin registro? (sí/no)

**Pregunta 5: Equipos**
- Opciones:
  - Solo individual
  - Solo por equipos
  - Ambos (configurable)

**Pregunta 6: Rondas**
- ¿Tiene rondas? (sí/no)
- Si sí: ¿Cuántas rondas?

**Pregunta 7: Turnos**
- Opciones:
  - Simultáneo - Todos juegan al mismo tiempo
  - Secuencial - Un jugador a la vez
  - Libre - No hay concepto de turno
  - Por equipos

**Pregunta 8: Puntuación**
- ¿Tiene sistema de puntos? (sí/no)
- Si sí: Describe cómo se puntúa

**Pregunta 9: Timer**
- Opciones:
  - Sí, por ronda
  - Sí, por turno
  - Sí, para toda la partida
  - No necesita timer
- Si sí: ¿Duración en segundos?
- ¿Qué pasa cuando expira?

**Pregunta 10: Roles**
- ¿Tiene roles especiales? (sí/no)
- Si sí: Lista los roles

**Pregunta 11: Estado del Jugador**
- ¿Los jugadores pueden bloquearse/eliminarse temporalmente? (sí/no)

**Pregunta 12: Elementos Custom**
- Selecciona elementos especiales (múltiple selección):
  - Mazo de cartas
  - Tablero/grid
  - Canvas de dibujo
  - Modo espectador
  - Replay/historial
  - Bots/IA
  - Ninguno

### Paso 2: Mapear Módulos

Basándote en las respuestas, determinar qué módulos activar:

| Respuesta | Módulo | Config |
|-----------|--------|--------|
| Permite invitados | `guest_system` | enabled: true |
| Equipos | `teams_system` | enabled: true, min/max teams |
| Rondas | `round_system` | enabled: true, total_rounds |
| Turnos secuenciales | `turn_system` | enabled: true, mode: sequential |
| Puntuación | `scoring_system` | enabled: true + ScoreCalculator |
| Timer | `timer_system` | enabled: true, duration |
| Roles | `roles_system` | enabled: true, roles list |
| Locks | `player_state_system` | enabled: true, uses_locks |
| Cartas | `card_deck_system` | enabled: true |
| Tablero | `board_grid_system` | enabled: true |
| Espectador | `spectator_mode` | enabled: true |
| Replay | `replay_history` | enabled: true |
| Bots | `ai_players` | enabled: true |

**Módulos SIEMPRE activos**:
- `game_core`
- `room_manager`
- `real_time_sync`

### Paso 3: Validaciones

Verificar ANTES de generar archivos:

- [ ] El slug no existe en `games/`
- [ ] Todos los módulos están soportados
- [ ] No hay conflictos (ej: turnos libres + timer por turno)
- [ ] Configuración es consistente

Si hay conflictos, advertir al usuario y pedir confirmación.

### Paso 4: Generar PRD (Product Requirements Document)

Crear archivo `prds/game-{slug}.md` con el siguiente formato:

```markdown
# {Game Name} - Product Requirements Document

## Overview
**Game Type**: {tipo de juego}
**Description**: {descripción del usuario}
**Target Audience**: {según min/max jugadores}

## Core Gameplay

### Players
- **Min Players**: {min}
- **Max Players**: {max}
- **Guest Support**: {yes/no}

### Teams
{si tiene equipos}
- **Mode**: {Individual/Teams/Both}
- **Min Teams**: {min}
- **Max Teams**: {max}

### Game Structure
{si tiene rondas}
- **Rounds**: {número de rondas}
- **Turn Mode**: {Simultaneous/Sequential/Free/Team-based}

### Timing
{si tiene timer}
- **Timer Type**: {Per round/Per turn/Entire game}
- **Duration**: {X seconds}
- **On Expiration**: {acción cuando expira}

### Scoring
{si tiene puntuación}
- **Point System**: {descripción de cómo se puntúa}
- **Speed Bonus**: {yes/no - si timer}
- **Penalties**: {descripción}

### Special Features
{si tiene roles}
- **Roles**: {lista de roles}

{si tiene locks}
- **Player Locks**: {descripción de cuándo se bloquean jugadores}

{si tiene elementos custom}
- **Custom Elements**:
  - {lista de elementos: cards, board, canvas, etc.}

## Technical Architecture

### Modules Enabled
{lista de módulos configurados con sus settings}

- `game_core` (always active)
- `room_manager` (always active)
- `real_time_sync` (WebSockets)
{más módulos según configuración}

### Key Implementation Points

#### Backend
- **Engine**: `games/{slug}/{GameName}Engine.php`
- **Score Calculator**: {si scoring_system} `games/{slug}/{GameName}ScoreCalculator.php`
- **Game State**: {descripción del estado clave}

#### Frontend
- **Client**: `games/{slug}/js/{GameName}GameClient.js`
- **UI**: `games/{slug}/views/game.blade.php`
- **Real-time Events**: {lista de eventos clave}

### File Structure
```
games/{slug}/
├── {GameName}Engine.php
├── {GameName}ScoreCalculator.php (if scoring)
├── config.json
├── questions.json (if Q&A type)
├── rules.json
├── views/
│   └── game.blade.php
└── js/
    └── {GameName}GameClient.js
```

## User Experience Flow

### 1. Lobby Phase
- Players join room
- Host configures settings
- {más detalles según features}

### 2. Game Start
- {descripción de cómo inicia el juego}
- {asignación de roles si aplica}
- {formación de equipos si aplica}

### 3. Round Flow
- {descripción de cada ronda}
- {mecánica de turnos}
- {timing si aplica}

### 4. Scoring & Results
- {cómo se calculan puntos}
- {cuándo se muestran resultados}

### 5. Game End
- Final scores
- Winner determination
- Replay option

## Development Notes

### Must Implement (TODOs)
1. `initialize()` - Load game resources
2. `onGameStart()` - Prepare first round
3. `processRoundAction()` - Handle player actions
4. `startNewRound()` - Setup each round
5. `endCurrentRound()` - Calculate round results
{más TODOs según módulos}

### References
- `docs/GAME_MODULES_REFERENCE.md` - Module details
- `docs/TIMER_SYSTEM_INTEGRATION.md` - Timer implementation
- `docs/BASE_ENGINE_CLIENT_DESIGN.md` - Architecture patterns
- `games/trivia/` - Reference implementation
```

Guardar este archivo para que `/generate-tasks` pueda leerlo.

### Paso 5: Generar Estructura

Crear todos estos archivos usando los templates de `CREATE_GAME_GUIDE.md`:

```
games/{slug}/
├── {GameName}Engine.php           # Template con TODOs
├── {GameName}ScoreCalculator.php  # Solo si scoring_system enabled
├── config.json                    # Módulos configurados
├── questions.json                 # Solo si tipo Q&A
├── rules.json
├── views/
│   └── game.blade.php             # Template UI
└── js/
    └── {GameName}GameClient.js    # Template cliente
```

**IMPORTANTE**:
- Usar los templates EXACTOS de `CREATE_GAME_GUIDE.md`
- Añadir comentarios `// TODO:` en lugares que requieren lógica específica
- NO duplicar código de `BaseGameEngine`
- Seguir convenciones de `CONVENTIONS.md`

### Paso 6: Crear Lista de Tareas (Opcional - para tracking inmediato)

Usar `TodoWrite` para crear lista de tareas básica (high-level) mientras el usuario usa /generate-tasks para detalles:

```markdown
Fase 1: Setup Básico ✅
- [x] Estructura creada
- [x] Módulos configurados

Fase 2: Lógica Core 🚧
- [ ] Implementar initialize()
- [ ] Implementar onGameStart()
- [ ] Implementar processRoundAction()
- [ ] Implementar startNewRound()
- [ ] Implementar endCurrentRound()

Fase 3: Puntuación (si aplica) ⏳
- [ ] Completar ScoreCalculator
- [ ] Implementar speed bonus
- [ ] Probar cálculo de puntos

Fase 4: Frontend ⏳
- [ ] Implementar GameClient
- [ ] Crear UI
- [ ] Integrar timer visual

Fase 5: Testing 📝
- [ ] Test: Inicialización
- [ ] Test: Flujo de rondas
- [ ] Test: Puntuación

Fase 6: Polish 🎨
- [ ] Assets
- [ ] Animaciones
- [ ] Documentación
```

### Paso 6: Validar Archivos

Ejecutar validaciones:

```bash
# Verificar sintaxis PHP
php -l games/{slug}/{GameName}Engine.php

# Verificar JSON
cat games/{slug}/config.json | jq

# Listar archivos creados
ls -la games/{slug}/
```

### Paso 8: Next Steps

Mostrar al usuario:

```
✨ ¡Juego "{Game Name}" creado con éxito!

📂 Archivos generados:
✅ games/{slug}/{GameName}Engine.php
✅ games/{slug}/{GameName}ScoreCalculator.php (si scoring)
✅ games/{slug}/config.json
✅ games/{slug}/views/game.blade.php
✅ games/{slug}/js/{GameName}GameClient.js
✅ prds/game-{slug}.md (PRD)

🎮 Módulos configurados:
- round_system (X rondas)
- scoring_system (puntos + bonus)
- timer_system (Xs por ronda)
- turn_system (simultáneo/secuencial)
{... más módulos según configuración}

📋 Siguiente paso - Generar tareas detalladas:
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Ejecuta este comando para generar la lista de tareas:

  /generate-tasks prds/game-{slug}.md

Esto creará tasks/tasks-{slug}.md con todas las tareas
organizadas por fases.

Luego usa /process-task-list para implementar paso a paso.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📚 Documentación útil:
- docs/GAME_MODULES_REFERENCE.md - Detalles de módulos
- docs/TIMER_SYSTEM_INTEGRATION.md - Timer implementation
- docs/BASE_ENGINE_CLIENT_DESIGN.md - Arquitectura
- games/trivia/ - Referencia de implementación

🎮 Flujo completo:
1. ✅ /create-game (completado)
2. ⏭️  /generate-tasks prds/game-{slug}.md
3. ⏭️  /process-task-list tasks/tasks-{slug}.md
4. ⏭️  Implementar tareas una por una
5. ⏭️  Probar y refinar
```

## Reglas Importantes

### ✅ SÍ hacer:
- Usar templates de CREATE_GAME_GUIDE.md
- Añadir TODOs en lógica específica
- Configurar módulos según respuestas
- Validar contra convenciones
- Crear lista de tareas detallada

### ❌ NO hacer:
- Implementar lógica completa del juego
- Duplicar código de BaseGameEngine
- Modificar archivos del core sin permiso
- Generar código que no sigue convenciones
- Saltarse validaciones

## Casos Especiales

### Si requiere modificar core:
```
⚠️  ADVERTENCIA: Este juego requiere modificar BaseGameEngine

Archivos a modificar:
- app/Contracts/BaseGameEngine.php
  Razón: Nuevo hook para X

¿Continuar? (sí/no)
```

**SIEMPRE** pedir permiso explícito antes de modificar core.

### Si módulo no implementado:
```
⚠️  MÓDULO NO IMPLEMENTADO: card_deck_system

Este módulo aún no está completamente implementado.
Se generará estructura básica con TODOs.

¿Continuar? (sí/no)
```

## Principio Clave

**Generar esqueleto robusto, desarrollador rellena TODOs.**

El comando crea la estructura completa siguiendo convenciones, pero NO implementa la lógica específica del juego. Eso lo hace el desarrollador después.

---

## Ejecución

Cuando el usuario ejecute `/create-game`:

1. Lee `docs/CREATE_GAME_GUIDE.md` completo
2. Usa `AskUserQuestion` para las 12 preguntas
3. Mapea módulos según respuestas
4. Valida configuración
5. Genera estructura con templates
6. Crea lista de tareas con `TodoWrite`
7. Valida archivos generados
8. Muestra next steps al usuario

**¡Comencemos!** 🚀
