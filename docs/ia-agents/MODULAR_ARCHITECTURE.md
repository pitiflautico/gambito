# Arquitectura Modular de Gambito

## Visión General

Gambito es una plataforma modular para **juegos presenciales** donde cada juego es un **plugin independiente** que puede activar/desactivar funcionalidades según sus necesidades específicas.

### 🎯 Concepto Clave
**Reuniones físicas con dispositivos móviles**: Los jugadores están en el mismo lugar físico, pero cada uno usa su dispositivo para interactuar con el juego. No es necesario chat porque pueden hablar en persona.

---

## 🎯 Principios de Diseño

### 1. **Configuración Declarativa**
Cada juego declara en su archivo `config.json` qué módulos necesita:
- ✅ Si necesita - Se activa y configura
- ❌ Si no necesita - Se desactiva completamente (sin overhead)

### 2. **Plug & Play**
Los juegos se instalan como carpetas independientes en `games/{slug}/`:
```
games/
├── uno/
│   ├── config.json          # Configuración del juego
│   ├── GameController.php   # Lógica del juego
│   ├── views/               # Vistas Blade específicas
│   └── routes.php           # Rutas del juego (opcional)
├── trivia/
└── adivinanza/
```

### 3. **Microservicios Opcionales**
Cada módulo es un microservicio que puede estar activo o no según el juego.

---

## 📦 Módulos del Sistema

### **CORE (Siempre Activos)**

#### 1. **Game Core**
- **Función**: Gestión básica del juego
- **Siempre activo**: ✅ Sí
- **Responsabilidades**:
  - Cargar configuración del juego
  - Ciclo de vida (start, pause, resume, finish)
  - Estado global del juego
  - Validaciones básicas

#### 2. **Room Manager**
- **Función**: Gestión de salas
- **Siempre activo**: ✅ Sí
- **Responsabilidades**:
  - Crear/cerrar salas
  - Generar códigos únicos
  - Estado de la sala (waiting, playing, finished)
  - URLs de invitación y QR

---

### **MÓDULOS OPCIONALES (Configurables)**

#### 3. **Guest System** 🎭
- **Función**: Sistema de invitados sin registro
- **Configurable**: ✅ Sí
- **Configuración en `config.json`**:
```json
{
  "modules": {
    "guests": {
      "enabled": true,
      "allow_reconnect": true,
      "session_timeout": 30,  // minutos
      "max_guests_per_room": 10
    }
  }
}
```

**Casos de uso:**
- ✅ **Activado**: Juegos multijugador con varios dispositivos (UNO, Trivia, Pictionary)
- ❌ **Desactivado**: Juegos de un solo dispositivo (Solitario, Sudoku, Memory local)

**Si está desactivado:**
- No se muestra opción de "Unirse con código"
- No se genera QR de invitación
- Solo el creador (master) puede jugar

---

#### 4. **Turn System** 🔄
- **Función**: Gestión de turnos entre jugadores
- **Configurable**: ✅ Sí
- **Configuración**:
```json
{
  "modules": {
    "turns": {
      "enabled": true,
      "mode": "sequential",  // sequential | free | vote
      "turn_timeout": 60,    // segundos por turno
      "skip_allowed": true,
      "auto_next_on_timeout": true
    }
  }
}
```

**Modos de turnos:**
- `sequential`: Turnos ordenados (1→2→3→1)
- `free`: Sin turnos, todos juegan simultáneamente
- `vote`: El siguiente turno se decide por votación

**Casos de uso:**
- ✅ **Activado**: UNO, Ajedrez, Trivia por turnos
- ❌ **Desactivado**: Kahoot (todos responden a la vez), Bingo

**Si está desactivado:**
- No hay concepto de "turno actual"
- Todos los jugadores pueden actuar simultáneamente
- No hay timeouts de turno

---

#### 5. **Real-time Sync (WebSockets)** 🔌
- **Función**: Sincronización en tiempo real
- **Configurable**: ✅ Sí
- **Configuración**:
```json
{
  "modules": {
    "realtime": {
      "enabled": true,
      "driver": "pusher",     // pusher | soketi | reverb
      "events": [
        "player.joined",
        "player.left",
        "turn.changed",
        "card.played",
        "game.updated"
      ]
    }
  }
}
```

**Casos de uso:**
- ✅ **Activado**: Juegos que requieren sincronización instantánea (UNO, Pictionary, Trivia en vivo)
- ❌ **Desactivado**: Juegos por turnos lentos (Chess por email, Trivia con polling)

**Fallback si está desactivado:**
- Polling HTTP cada 3-5 segundos
- Refresh manual del usuario

---

#### 6. **Round System** 🔄
- **Función**: Control de rondas del juego
- **Configurable**: ✅ Sí
- **Configuración**:
```json
{
  "modules": {
    "rounds": {
      "enabled": true,
      "mode": "configurable",  // fixed | configurable | unlimited
      "default": 5,            // Rondas por defecto
      "min": 3,                // Mínimo si es configurable
      "max": 10                // Máximo si es configurable
    }
  }
}
```

**Modos de rondas:**
- `fixed`: Número fijo predefinido (ej: 10 rondas siempre)
- `configurable`: Master elige al crear sala (ej: entre 3 y 10)
- `unlimited`: Sin límite, termina por otra condición (puntos, tiempo)

**Casos de uso:**
- ✅ **Activado**: Juegos con rondas definidas (Pictionary, Trivia)
- ❌ **Desactivado**: Juegos sin rondas o que terminan por puntos/tiempo

**Integración con Turn System:**
```
Ronda 1: Turno 1 (JugadorA), Turno 2 (JugadorB), Turno 3 (JugadorC)
Ronda 2: Turno 1 (JugadorA), Turno 2 (JugadorB), Turno 3 (JugadorC)
...
Fin: round >= rounds_total
```

**Si está desactivado:**
- No se muestra "Ronda X de Y"
- El juego termina por otra condición de victoria
- `game_state.round` es opcional

---

#### 7. **Scoring System** 🏆
- **Función**: Sistema de puntuación
- **Configurable**: ✅ Sí
- **Configuración**:
```json
{
  "modules": {
    "scoring": {
      "enabled": true,
      "mode": "points",       // points | ranking | binary
      "points_config": {
        "min": 0,
        "max": null,          // sin límite
        "can_be_negative": true,
        "precision": 1        // decimales permitidos
      },
      "show_realtime": true,  // mostrar puntuación durante el juego
      "show_ranking": true    // mostrar clasificación final
    }
  }
}
```

**Modos de puntuación:**
- `points`: Suma de puntos (Trivia, UNO)
- `ranking`: Posición relativa (1º, 2º, 3º)
- `binary`: Ganador/Perdedor (Ajedrez, Tres en Raya)

**Casos de uso:**
- ✅ **Activado**: Mayoría de juegos competitivos
- ❌ **Desactivado**: Juegos colaborativos sin ganador (Party games, juegos narrativos)

---

#### 8. **Teams System** 👥
- **Función**: Agrupación de jugadores en equipos
- **Configurable**: ✅ Sí
- **Configuración**:
```json
{
  "modules": {
    "teams": {
      "enabled": true,
      "mode": "manual",       // manual | auto | random
      "min_teams": 2,
      "max_teams": 4,
      "min_players_per_team": 1,
      "max_players_per_team": null,
      "allow_team_switch": false
    }
  }
}
```

**Casos de uso:**
- ✅ **Activado**: Pictionary por equipos, Trivial Pursuit, Charadas
- ❌ **Desactivado**: Juegos individuales (UNO, Solitario)

---

#### 9. **~~Chat System~~** ❌ **ELIMINADO**
- **Razón**: Gambito es para juegos presenciales. Los jugadores hablan en persona.
- **Alternativa**: Emojis/reacciones opcionales para feedback visual rápido (implementar solo si es necesario)

---

#### 10. **Timer System** ⏱️
- **Función**: Temporizadores y límites de tiempo
- **Configurable**: ✅ Sí
- **Configuración**:
```json
{
  "modules": {
    "timer": {
      "enabled": true,
      "game_duration": null,   // null = sin límite
      "turn_duration": 30,     // segundos por turno
      "action_duration": 10,   // segundos para acción específica
      "show_timer": true,
      "warning_threshold": 5,  // segundos antes de acabar
      "pause_allowed": true
    }
  }
}
```

**Casos de uso:**
- ✅ **Activado**: Trivia, juegos de velocidad, party games
- ❌ **Desactivado**: Ajedrez (tiempo ilimitado), juegos relajados

---

#### 11. **Roles System** 🎭
- **Función**: Roles específicos dentro del juego
- **Configurable**: ✅ Sí
- **Configuración**:
```json
{
  "modules": {
    "roles": {
      "enabled": true,
      "roles": [
        {
          "name": "drawer",
          "max_count": 1,
          "rotation": true
        },
        {
          "name": "guesser",
          "max_count": null
        }
      ],
      "auto_assign": true,
      "allow_role_swap": false
    }
  }
}
```

**Casos de uso:**
- ✅ **Activado**: Pictionary (dibujante vs adivinadores), Mafia (roles secretos)
- ❌ **Desactivado**: UNO, Trivia (todos tienen mismo rol)

---

#### 12. **Card/Deck System** 🎴
- **Función**: Gestión de mazos de cartas
- **Configurable**: ✅ Sí
- **Configuración**:
```json
{
  "modules": {
    "deck": {
      "enabled": true,
      "deck_type": "uno",      // uno | poker | custom
      "shuffle_on_start": true,
      "reshuffle_discard": true,
      "hand_size": 7,
      "show_hand_to_owner_only": true
    }
  }
}
```

**Casos de uso:**
- ✅ **Activado**: UNO, Poker, juegos de cartas
- ❌ **Desactivado**: Trivia, Pictionary, juegos sin cartas

---

#### 13. **Board/Grid System** 🎯
- **Función**: Tablero o cuadrícula de juego
- **Configurable**: ✅ Sí
- **Configuración**:
```json
{
  "modules": {
    "board": {
      "enabled": true,
      "type": "grid",          // grid | canvas | path
      "dimensions": {
        "rows": 8,
        "cols": 8
      },
      "cell_type": "piece"     // piece | color | empty
    }
  }
}
```

**Casos de uso:**
- ✅ **Activado**: Ajedrez, Damas, Tres en Raya
- ❌ **Desactivado**: UNO, Trivia, juegos sin tablero

---

#### 14. **Spectator Mode** 👁️
- **Función**: Permitir espectadores sin jugar
- **Configurable**: ✅ Sí
- **Configuración**:
```json
{
  "modules": {
    "spectators": {
      "enabled": true,
      "max_spectators": 50,
      "can_chat": false,
      "see_all_hands": false,  // ¿ven cartas privadas?
      "join_after_start": true
    }
  }
}
```

**Casos de uso:**
- ✅ **Activado**: Torneos, streams, juegos competitivos
- ❌ **Desactivado**: Juegos privados, juegos con información secreta

---

#### 15. **AI Players** 🤖
- **Función**: Bots/IA como jugadores
- **Configurable**: ✅ Sí
- **Configuración**:
```json
{
  "modules": {
    "ai": {
      "enabled": true,
      "difficulty_levels": ["easy", "medium", "hard"],
      "max_ai_players": 3,
      "auto_fill_with_ai": false,
      "ai_turn_delay": 2       // segundos de "pensamiento"
    }
  }
}
```

**Casos de uso:**
- ✅ **Activado**: Juegos que pueden jugarse solo, práctica
- ❌ **Desactivado**: Juegos puramente sociales

---

#### 16. **Replay/History System** 📹
- **Función**: Grabación y reproducción de partidas
- **Configurable**: ✅ Sí
- **Configuración**:
```json
{
  "modules": {
    "replay": {
      "enabled": true,
      "save_moves": true,
      "save_chat": false,
      "allow_download": true,
      "retention_days": 30
    }
  }
}
```

**Casos de uso:**
- ✅ **Activado**: Ajedrez (análisis), torneos
- ❌ **Desactivado**: Juegos casuales, party games

---

## ⚙️ Decisiones Técnicas

### **1. WebSockets: Laravel Reverb**
- **Elección**: Laravel Reverb (nativo de Laravel 11)
- **Ventajas**:
  - ✅ Integración nativa con Laravel
  - ✅ Gratuito y open-source
  - ✅ Broadcast events simplificado
  - ✅ Compatible con Pusher protocol (migración fácil)
- **Preparado para escalado**: Puede moverse a servidor separado sin cambiar código

### **2. Configuración: Híbrida (JSON + Base de Datos)**
```
games/{slug}/config.json    → Configuración base del juego (defaults)
                             → Versionado en Git
                             → No cambia en runtime

table: game_configurations   → Overrides por instalación
                             → Permite a admins ajustar sin tocar archivos
                             → Cache en Redis para performance
```

**Flujo de carga**:
1. Leer `config.json` del juego (defaults)
2. Buscar overrides en BD
3. Mergear configuración
4. Cachear en Redis por 1 hora

**Ejemplo**:
```php
// games/uno/config.json (defaults)
{
  "max_players": 10,
  "turn_timeout": 60
}

// BD override (admin puede cambiar)
UPDATE game_configurations
SET config = '{"max_players": 8}'
WHERE game_id = 1;

// Resultado final mergeado
{
  "max_players": 8,      // ← Override de BD
  "turn_timeout": 60     // ← Default de JSON
}
```

### **3. Deployment: Monolito preparado para Microservicios**
- **Ahora**: Todo en un servidor Laravel
- **Futuro**: Módulos pueden separarse sin refactorizar

**Arquitectura actual**:
```
┌─────────────────────────────────┐
│   Laravel App (Monolito)        │
│                                 │
│  ┌──────────────────────────┐  │
│  │   Game Core              │  │
│  │   Room Manager           │  │
│  │   Guest Module           │  │
│  │   Turn Module            │  │
│  │   Scoring Module         │  │
│  │   Timer Module           │  │
│  └──────────────────────────┘  │
│                                 │
│  ┌──────────────────────────┐  │
│  │   Laravel Reverb         │  │
│  │   (WebSockets)           │  │
│  └──────────────────────────┘  │
└─────────────────────────────────┘
```

**Futuro escalado** (si es necesario):
```
┌─────────────────┐      ┌──────────────────┐
│  Laravel App    │◄────►│  Reverb Server   │
│  (HTTP/Logic)   │      │  (WebSockets)    │
└─────────────────┘      └──────────────────┘
        │
        ├──► Redis (Cache + Pub/Sub)
        ├──► MySQL (Persistence)
        └──► Queue Workers (Jobs)
```

**Principio de diseño**:
- Cada módulo usa **interfaces** en vez de implementaciones concretas
- Communication via **Events** (fácil cambiar a message queue)
- Configuración centralizada (puede moverse a config server)

---

## 🔧 Implementación Técnica

### Archivo de Configuración del Juego

**`games/uno/config.json`**:
```json
{
  "name": "UNO",
  "slug": "uno",
  "version": "1.0.0",
  "description": "El clásico juego de cartas UNO",
  "min_players": 2,
  "max_players": 10,
  "estimated_duration": "15-30 minutos",
  "is_active": true,
  "thumbnail": "/images/games/uno.png",

  "modules": {
    "guests": {
      "enabled": true,
      "max_guests_per_room": 10
    },
    "turns": {
      "enabled": true,
      "mode": "sequential",
      "turn_timeout": 60
    },
    "realtime": {
      "enabled": true,
      "driver": "pusher"
    },
    "scoring": {
      "enabled": true,
      "mode": "points"
    },
    "teams": {
      "enabled": false
    },
    "timer": {
      "enabled": true,
      "turn_duration": 30
    },
    "roles": {
      "enabled": false
    },
    "deck": {
      "enabled": true,
      "deck_type": "uno",
      "hand_size": 7
    },
    "board": {
      "enabled": false
    },
    "spectators": {
      "enabled": true,
      "can_chat": false
    },
    "ai": {
      "enabled": true,
      "difficulty_levels": ["easy", "medium", "hard"]
    },
    "replay": {
      "enabled": false
    }
  }
}
```

---

### Sistema de Carga Modular

**`app/Services/Core/ModuleLoader.php`**:
```php
class ModuleLoader
{
    public function loadGameModules(Game $game): array
    {
        $config = $this->getGameConfig($game);
        $activeModules = [];

        foreach ($config['modules'] as $module => $settings) {
            if ($settings['enabled'] === true) {
                $activeModules[$module] = $this->initializeModule($module, $settings);
            }
        }

        return $activeModules;
    }

    protected function initializeModule(string $module, array $settings): ModuleInterface
    {
        $moduleClass = "App\\Modules\\" . ucfirst($module) . "Module";
        return new $moduleClass($settings);
    }
}
```

---

### Interfaz de Módulo

**`app/Modules/ModuleInterface.php`**:
```php
interface ModuleInterface
{
    public function boot(GameMatch $match): void;
    public function isEnabled(): bool;
    public function getConfig(): array;
    public function handleEvent(string $event, array $data): mixed;
}
```

---

## 🎮 Ejemplos de Configuración

### Juego 1: UNO (Multijugador Presencial)
```json
{
  "modules": {
    "guests": { "enabled": true },
    "turns": { "enabled": true, "mode": "sequential" },
    "realtime": { "enabled": true },
    "scoring": { "enabled": true },
    "deck": { "enabled": true }
  }
}
```

### Juego 2: Solitario (Un Solo Dispositivo)
```json
{
  "modules": {
    "guests": { "enabled": false },
    "turns": { "enabled": false },
    "realtime": { "enabled": false },
    "scoring": { "enabled": true, "mode": "points" },
    "deck": { "enabled": true },
    "timer": { "enabled": true }
  }
}
```

### Juego 3: Pictionary (Equipos + Roles)
```json
{
  "modules": {
    "guests": { "enabled": true },
    "turns": { "enabled": true },
    "realtime": { "enabled": true },
    "teams": { "enabled": true, "min_teams": 2 },
    "roles": { "enabled": true, "roles": ["drawer", "guesser"] },
    "timer": { "enabled": true, "turn_duration": 60 }
  }
}
```
*Nota: Los jugadores hablan en persona para adivinar, no necesitan chat digital.*

### Juego 4: Trivia en Vivo (Todos Simultáneos)
```json
{
  "modules": {
    "guests": { "enabled": true },
    "turns": { "enabled": false },
    "realtime": { "enabled": true },
    "scoring": { "enabled": true },
    "timer": { "enabled": true, "action_duration": 15 },
    "spectators": { "enabled": true }
  }
}
```

---

## 📊 Ventajas de esta Arquitectura

### 1. **Rendimiento Óptimo**
- Solo se cargan los módulos que el juego necesita
- No hay overhead de funcionalidades no utilizadas
- Menos consultas a BD y menos memoria

### 2. **Desarrollo Ágil**
- Cada juego se desarrolla independientemente
- Los módulos son reutilizables entre juegos
- Fácil agregar nuevos juegos

### 3. **Escalabilidad**
- Módulos pueden desplegarse como microservicios separados
- WebSockets solo para juegos que lo necesiten
- Base de datos puede optimizarse por módulo

### 4. **Mantenibilidad**
- Código organizado por responsabilidad
- Bugs en un módulo no afectan a otros
- Tests unitarios por módulo

### 5. **Flexibilidad**
- Juegos pueden activar/desactivar features fácilmente
- Configuración sin tocar código
- Experimentación rápida

---

## 🚀 Próximos Pasos

1. ✅ **Crear sistema base de carga de módulos**
2. ✅ **Implementar ModuleInterface y clases base**
3. ✅ **Crear primer juego de ejemplo con configuración modular**
4. ⏳ **Migrar sistema de salas actual a arquitectura modular**
5. ⏳ **Implementar cada módulo como servicio independiente**

---

## 📝 Notas de Implementación

- Los módulos se cargan **lazy** (solo cuando se necesitan)
- La configuración se cachea en Redis para performance
- Los eventos de módulos usan el sistema de eventos de Laravel
- Cada módulo puede tener sus propias migraciones de BD
- La interfaz de usuario se adapta según módulos activos

---

**Última actualización**: 2025-10-20
**Autor**: Documentación generada con Claude Code
