---
description: Asistente interactivo para crear juegos siguiendo la arquitectura modular
---

# Comando: /create-game - Crear Juego Paso a Paso

Eres un asistente experto en la arquitectura modular de este proyecto. Tu objetivo es crear un nuevo juego de forma **estructurada y dividida en fases**, siguiendo TODAS las convenciones documentadas.

## 📚 Documentación de Referencia (LEER ANTES DE EMPEZAR)

**CRÍTICO**: Estos documentos contienen TODAS las convenciones y errores comunes:
- `docs/EVENTOS_Y_ERRORES_CRITICOS.md` - ⚠️ Errores críticos (PRIORIDAD 1)
- `docs/CREAR_JUEGO_PASO_A_PASO.md` - Guía paso a paso con checklists
- `docs/GUIA_COMPLETA_MOCKUP_GAME.md` - Arquitectura completa de referencia
- `.claude/commands/create-game/RESUMEN_FASES.md` - Resumen de fases 7-12

## 🎯 Filosofía: Sistema de Fases con `/create-tasks`

Este comando divide la creación en **12 FASES ESTRUCTURADAS**:

```
FASE 1: Lectura del Archivo de Diseño (DESIGN.md del juego)
  ↓
FASE 2: Análisis y Preguntas de Clarificación
  ↓
FASE 3: Estructura Base (crear directorios y archivos vacíos)
  ↓
FASE 4: Configuración (config.json + capabilities.json)
  ↓
FASE 5: Eventos - Declaración (crear clases PHP)
  ↓
FASE 6: Eventos - Registro (capabilities.json + config.json)
  ↓
FASE 7: Engine - Estructura Base (initialize + onGameStart)
  ↓
FASE 8: Engine - Ciclo de Rondas (startNewRound + processRoundAction)
  ↓
FASE 9: Engine - Fases y Callbacks (handle{Fase}Ended)
  ↓
FASE 10: Frontend - Cliente Base (setupEventManager + handlers)
  ↓
FASE 11: Frontend - UI y Vistas (game.blade.php + popups)
  ↓
FASE 12: Testing y Validación Final
```

**Reglas**:
- Cada fase usa `/create-tasks` con tareas específicas y checklists
- No avanzar sin completar checklist de la fase actual
- Cada fase referencia las secciones relevantes de los documentos

---

## ⚡ INICIO DEL COMANDO

Cuando el usuario ejecute `/create-game`, seguir este flujo:

---

## 🔍 FASE 1: Lectura del Archivo de Diseño

**Objetivo**: Leer y analizar el archivo DESIGN.md del juego.

**Pasos**:

1. Preguntar al usuario por el nombre/slug del juego
2. Buscar archivo `games/{slug}/DESIGN.md`
3. Si existe: Leer y analizar su contenido
4. Si NO existe: Preguntar si quiere crear uno o modo interactivo

**Análisis del DESIGN.md**:
- Extraer: nombre, slug, descripción
- Extraer: número de jugadores (min/max)
- Extraer: fases del juego (nombre, duración, descripción)
- Extraer: mecánicas (puntuación, bloqueos, roles, etc.)
- Extraer: eventos custom necesarios vs genéricos
- Identificar ambigüedades o información faltante

**Output de esta fase**:
```
📊 Análisis del Juego:
✅ Nombre: {nombre}
✅ Slug: {slug}
✅ Jugadores: {min}-{max}
✅ Fases: {N} fases identificadas
⚠️  Ambigüedades detectadas: {lista}
```

---

## ❓ FASE 2: Preguntas de Clarificación

**Objetivo**: Resolver ambigüedades antes de generar código.

**IMPORTANTE**: Solo preguntar sobre lo que NO esté claro. No preguntar obviedades.

**Preguntas típicas**:
- ¿Cuántas rondas tiene el juego? (si no está en DESIGN.md)
- ¿La fase X necesita evento custom o genérico?
- ¿Cómo se calculan los puntos exactamente?
- ¿Hay roles específicos? (dibujante, votante, etc.)
- ¿Qué pasa si el timer expira en fase X?
- ¿Los jugadores pueden realizar acciones simultáneas o secuenciales?

**Output de esta fase**:
```
✅ Configuración Completa:
- Total de rondas: 5
- Fase 1 (preparation): 10s, evento custom
- Fase 2 (playing): 60s, evento custom
- Fase 3 (voting): 15s, evento custom
- Puntos: +10 por voto positivo
- Sin roles específicos
```

---

## 📂 FASE 3: Estructura Base

**Objetivo**: Crear estructura de directorios y archivos vacíos.

**Instrucciones**: Usar `/create-tasks` con el siguiente contenido markdown:

### Contenido para /create-tasks:

```markdown
# Fase 3: Estructura Base - {GameName}

## Context
Crear la estructura completa de directorios y archivos vacíos para el juego {slug}.

## Task 1: Crear Directorios
- [ ] `games/{slug}/`
- [ ] `games/{slug}/js/`
- [ ] `games/{slug}/views/`
- [ ] `games/{slug}/views/partials/`
- [ ] `app/Events/{GameName}/`

## Task 2: Crear Archivos Vacíos
- [ ] `games/{slug}/config.json` (archivo vacío por ahora)
- [ ] `games/{slug}/capabilities.json` (archivo vacío por ahora)
- [ ] `games/{slug}/{GameName}Engine.php` (archivo vacío)
- [ ] `games/{slug}/{GameName}ScoreCalculator.php` (archivo vacío)
- [ ] `games/{slug}/js/{GameName}Client.js` (archivo vacío)
- [ ] `games/{slug}/views/game.blade.php` (archivo vacío)
- [ ] `games/{slug}/views/partials/round_end_popup.blade.php` (vacío)
- [ ] `games/{slug}/views/partials/game_end_popup.blade.php` (vacío)
- [ ] `games/{slug}/views/partials/player_disconnected_popup.blade.php` (vacío)

## Task 3: Validación
```bash
ls -R games/{slug}/
ls app/Events/{GameName}/
```
✅ Debe mostrar toda la estructura creada
```

---

## ⚙️ FASE 4: Configuración (config.json + capabilities.json)

**Objetivo**: Crear archivos de configuración completos y válidos.

**CRÍTICO**: Leer sección "FASE 3: Configuración" de `docs/CREAR_JUEGO_PASO_A_PASO.md` Y sección "capabilities.json vs config.json" de `docs/EVENTOS_Y_ERRORES_CRITICOS.md`.

**Instrucciones**: Usar `/create-tasks` con el siguiente contenido markdown:

### Contenido para /create-tasks:

```markdown
# Fase 4: Configuración - {GameName}

## Context
Crear config.json y capabilities.json con TODAS las convenciones correctas.

**⚠️ REGLA DE ORO**:
```
SI EL EVENTO NO LLEGA AL FRONTEND, 99% DE LAS VECES:
❌ Olvidaste registrarlo en capabilities.json
```

## Convenciones Críticas

**Tabla de Punto Inicial**:
| Archivo | ¿Punto Inicial? | Ejemplo |
|---------|-----------------|---------|
| `Event::broadcastAs()` | ❌ NO | `"tu-juego.fase.started"` |
| `config.json` | ✅ SÍ | `".tu-juego.fase.started"` |
| `capabilities.json` | ❌ NO | `"tu-juego.fase.started"` |

## Task 1: Crear config.json

Crear `games/{slug}/config.json` con:

1. **Info básica**:
   - id: "{slug}"
   - name: "{GameName}"
   - slug: "{slug}"
   - minPlayers: {min}
   - maxPlayers: {max}
   - estimatedDuration: {minutos}

2. **Timing**:
```json
"timing": {
  "round_ended": {
    "type": "countdown",
    "message": "Siguiente ronda en",
    "delay": 3,
    "auto_next": true
  }
}
```

3. **Módulos** (habilitar los necesarios):
   - game_core: enabled: true
   - room_manager: enabled: true
   - guest_system: enabled: true
   - round_system: enabled: true, total_rounds: {N}, inter_round_delay: 3
   - phase_system: enabled: true, phases: []
   - scoring_system: enabled: true
   - player_system: enabled: true
   - roles_system: enabled: true, roles: [] (ver sección de Roles abajo)
   - timer_system: enabled: true
   - real_time_sync: enabled: true

4. **roles_system.roles** - Sistema de Rotación de Roles:

**IMPORTANTE**: Leer `docs/ROLE_ROTATION_TYPES.md` para detalles completos.

El sistema detecta automáticamente el tipo de rotación desde la configuración:

**Tipo Sequential** (1 rol principal que rota + resto con rol secundario):
```json
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
```
- **count: 1** → Rol principal (solo 1 jugador)
- **count: -1** → Rol resto (todos los demás jugadores)
- **rotate_on_round_start: true** → Rota automáticamente cada ronda
- Rotación: secuencial circular (jugador 1 → 2 → 3 → 1...)

**Tipo Single** (todos tienen mismo rol, sin rotación):
```json
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
```

**Comportamiento automático**:
- `BaseGameEngine::initializeModules()` inicializa roles_system (obligatorio)
- Si no defines roles, usa rol por defecto "player"
- Rotación ocurre automáticamente en `handleNewRound()` cuando `advanceRound=true`
- NO necesitas código manual en el Engine

5. **phase_system.phases** - Para CADA fase:
```json
{
  "name": "fase1",  // lowercase, sin espacios
  "duration": 10,
  "on_start": "App\\Events\\{GameName}\\Fase1StartedEvent",
  "on_end": "App\\Events\\Game\\PhaseEndedEvent",
  "on_end_callback": "handleFase1Ended"  // camelCase
}
```

6. **event_config.events** - Para CADA evento custom:
```json
"Fase1StartedEvent": {
  "name": ".{slug}.fase1.started",  // CON punto inicial
  "handler": "handleFase1Started"    // camelCase
}
```

**⚠️ IMPORTANTE**:
- `on_end_callback`: SIEMPRE camelCase, patrón "handle{Fase}Ended"
- `event_config.events[].name`: SIEMPRE con punto inicial

## Task 2: Crear capabilities.json

Crear `games/{slug}/capabilities.json` con:

```json
{
  "events": {
    "Fase1StartedEvent": {
      "name": "{slug}.fase1.started",  // SIN punto inicial
      "description": "Fase 1 iniciada",
      "handler": "handleFase1Started"  // Mismo que config.json
    }
    // ... repetir para cada evento custom
  }
}
```

**⚠️ IMPORTANTE**:
- `name`: SIN punto inicial (diferente a config.json)
- `handler`: Debe coincidir EXACTAMENTE con config.json

## Task 3: Validación

```bash
# Validar sintaxis JSON
php -r "json_decode(file_get_contents('games/{slug}/config.json'));"
php -r "json_decode(file_get_contents('games/{slug}/capabilities.json'));"
```

**Checklist**:
- [ ] config.json: JSON válido
- [ ] config.json: Todas las fases definidas
- [ ] config.json: event_config con nombres CON punto inicial
- [ ] config.json: on_end_callback en camelCase
- [ ] capabilities.json: JSON válido
- [ ] capabilities.json: Eventos con nombres SIN punto inicial
- [ ] capabilities.json: Handlers coinciden con config.json
- [ ] Total de fases = Total de eventos custom
```

---

## 📢 FASE 5: Eventos - Declaración (Crear Clases PHP)

**Objetivo**: Crear clases PHP de eventos personalizados.

**CRÍTICO**: Leer `docs/EVENTOS_Y_ERRORES_CRITICOS.md` → Sección "Sistema de Eventos" y "Checklist Antes de Crear un Evento".

**Instrucciones**: Usar `/create-tasks` con el siguiente contenido markdown:

### Contenido para /create-tasks:

```markdown
# Fase 5: Eventos - Declaración - {GameName}

## Context
Crear TODAS las clases PHP de eventos personalizados siguiendo convenciones EXACTAS.

## Convenciones CRÍTICAS

**broadcastOn()**:
- SIEMPRE `PresenceChannel("room.{$this->roomCode}")`
- NUNCA `Channel` simple

**broadcastAs()**:
- SIN punto inicial
- Formato: "{slug}.fase.started"

**broadcastWith() DEBE incluir**:
- `room_code`
- `phase_name`
- `duration` (si tiene timer)
- `timer_id` (si tiene timer, ej: "timer")
- `server_time` (si tiene timer, now()->timestamp)
- `event_class` (evento al expirar)

## Task 1: Crear Evento Fase 1

**Archivo**: `app/Events/{GameName}/Fase1StartedEvent.php`

```php
<?php

namespace App\Events\{GameName};

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class Fase1StartedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public string $phase;
    public ?int $duration;
    public string $timerId;
    public int $serverTime;
    public array $phaseData;

    public function __construct(GameMatch $match, array $phaseConfig)
    {
        $this->roomCode = $match->room->code;
        $this->phase = 'fase1';  // Mismo nombre que config.json phases[].name
        $this->duration = $phaseConfig['duration'] ?? null;
        $this->timerId = 'timer';
        $this->serverTime = now()->timestamp;
        $this->phaseData = $phaseConfig;
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('room.' . $this->roomCode);
    }

    public function broadcastAs(): string
    {
        return '{slug}.fase1.started';  // SIN punto inicial
    }

    public function broadcastWith(): array
    {
        return [
            'room_code' => $this->roomCode,
            'phase_name' => $this->phase,
            'duration' => $this->duration,
            'timer_id' => $this->timerId,
            'server_time' => $this->serverTime,
            'phase_data' => $this->phaseData,
            'event_class' => $this->phaseData['on_end'] ?? 'App\\Events\\Game\\PhaseTimerExpiredEvent',
        ];
    }
}
```

**Checklist**:
- [ ] Namespace correcto
- [ ] Implements ShouldBroadcastNow
- [ ] Use traits correctos
- [ ] broadcastOn() usa PresenceChannel
- [ ] broadcastAs() SIN punto inicial
- [ ] broadcastWith() incluye duration, timer_id, server_time, event_class

## Task 2-N: Repetir para Cada Fase Custom

Para CADA fase que use evento personalizado (no genérico), crear archivo similar cambiando:
- Nombre de clase: `Fase2StartedEvent`, `Fase3StartedEvent`, etc.
- `$this->phase = 'fase2'`, `$this->phase = 'fase3'`, etc.
- `broadcastAs()` return: `'{slug}.fase2.started'`, `'{slug}.fase3.started'`, etc.

## Task Final: Validación

```bash
# Validar sintaxis PHP
php -l app/Events/{GameName}/*.php
```

✅ Debe mostrar "No syntax errors" para cada archivo

**Checklist Final**:
- [ ] Un archivo por cada fase con evento custom
- [ ] Todos los archivos validan sintaxis PHP
- [ ] broadcastAs() coincide con capabilities.json (sin punto)
- [ ] $this->phase coincide con config.json phases[].name
```

---

## 📝 FASE 6: Eventos - Registro

**Objetivo**: Validar que eventos estén registrados correctamente.

**Instrucciones**: Usar `/create-tasks` con el siguiente contenido markdown:

### Contenido para /create-tasks:

```markdown
# Fase 6: Eventos - Registro - {GameName}

## Context
Validar que TODOS los eventos custom estén correctamente registrados en capabilities.json y config.json.

## Task 1: Validar capabilities.json

Para CADA evento personalizado:
- [ ] Existe entrada en `capabilities.json` → `events{}`
- [ ] `name` SIN punto inicial
- [ ] `handler` en camelCase
- [ ] `description` clara

## Task 2: Validar config.json

Para CADA evento personalizado:
- [ ] Existe entrada en `config.json` → `event_config.events{}`
- [ ] `name` CON punto inicial
- [ ] `handler` coincide con capabilities.json

## Task 3: Checklist Final
- [ ] TODOS los eventos custom en capabilities.json
- [ ] TODOS los eventos custom en config.json
- [ ] Nombres coinciden (excepto punto inicial)
- [ ] Handlers coinciden exactamente
- [ ] Total eventos en capabilities = Total eventos en config
- [ ] Total eventos custom = Total fases custom
```

---

## FASES 7-12: Continuación

**IMPORTANTE**: Para las fases 7-12, leer el documento `.claude/commands/create-game/RESUMEN_FASES.md` que contiene:

- **FASE 7**: Engine - Estructura Base (initialize + onGameStart)
- **FASE 8**: Engine - Ciclo de Rondas (startNewRound + processRoundAction + endCurrentRound)
- **FASE 9**: Engine - Fases y Callbacks (handle{Fase}Ended)
- **FASE 10**: Frontend - Cliente Base (setupEventManager + handlers)
- **FASE 11**: Frontend - UI y Vistas (game.blade.php + popups)
- **FASE 12**: Testing y Validación Final

Para cada una de estas fases:

1. **Leer la sección correspondiente** en `RESUMEN_FASES.md`
2. **Usar `/create-tasks`** con las convenciones y checklists especificados
3. **Referenciar documentos** según se indique en cada fase

---

## 🎯 Flujo de Ejecución

Cuando el usuario ejecute `/create-game`:

1. **Iniciar FASE 1**: Leer DESIGN.md o preguntar información
2. **Ejecutar FASE 2**: Hacer preguntas de clarificación
3. **Para FASES 3-6**: Generar `/create-tasks` según templates de arriba
4. **Para FASES 7-12**: Leer `RESUMEN_FASES.md` y generar `/create-tasks`
5. **Después de cada fase**: Validar checklist antes de continuar
6. **Al finalizar FASE 12**: Mostrar resumen completo

---

## 📋 Checklist Global de Validación

Al finalizar TODAS las fases, verificar:

### Configuración ✅
- [ ] config.json válido (JSON)
- [ ] capabilities.json válido (JSON)
- [ ] Punto inicial: CON punto en config.json, SIN punto en capabilities.json
- [ ] Todos los handlers coinciden
- [ ] Todos los módulos necesarios habilitados

### Backend ✅
- [ ] Eventos usan ShouldBroadcastNow + PresenceChannel
- [ ] broadcastAs() SIN punto inicial
- [ ] broadcastWith() incluye campos de timer
- [ ] Engine extiende BaseGameEngine
- [ ] initialize(), onGameStart(), startNewRound(), processRoundAction(), endCurrentRound() implementados
- [ ] Todos los callbacks handle{Fase}Ended implementados
- [ ] $phaseManager->setMatch($match) en TODOS los callbacks
- [ ] Patrón game_state: obtener → modificar → reasignar → guardar

### Frontend ✅
- [ ] Cliente extiende BaseGameClient
- [ ] setupEventManager() registra customHandlers
- [ ] Todos los handlers implementados
- [ ] handleDomLoaded() llama a super primero
- [ ] onPlayerLocked() y onPlayersUnlocked() implementados
- [ ] game.blade.php incluye @stack('scripts')
- [ ] game.blade.php incluye popups

### Validación ✅
- [ ] Sintaxis PHP válida
- [ ] Sintaxis JS válida
- [ ] JSON válido
- [ ] Assets compilados (npm run build)
- [ ] Testing manual completo

---

## 🚨 Errores Críticos a Recordar

1. **capabilities.json es CRÍTICO** - Sin él, eventos no llegan
2. **Punto Inicial** - SIN punto en broadcastAs() y capabilities.json, CON punto en config.json
3. **PresenceChannel** - Siempre usar para events de room
4. **game_state** - Siempre patrón: obtener → modificar → reasignar → guardar
5. **setMatch()** - SIEMPRE llamar antes de nextPhase()
6. **Timer** - Incluir duration, timer_id, server_time, event_class
7. **Handlers** - Deben coincidir en capabilities.json, config.json y Client.js
