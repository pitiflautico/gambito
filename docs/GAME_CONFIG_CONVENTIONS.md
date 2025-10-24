# Convenciones de Configuración de Juegos

## Principio: Convención sobre Configuración

Todos los juegos siguen las mismas convenciones de configuración para mantener consistencia y permitir que el sistema funcione de manera predecible.

## Módulos Obligatorios en config.json

Cada juego **DEBE** definir los siguientes módulos en su `config.json`, incluso si usan valores por defecto:

### 1. `roles_system` (OBLIGATORIO)

**Todos los juegos deben tener `roles_system` definido**, incluso si solo usan el rol genérico `"player"`.

#### ¿Por qué es obligatorio?

- **Consistencia**: El sistema siempre puede confiar en que existe
- **Extensibilidad**: Facilita agregar roles específicos en el futuro
- **Claridad**: Hace explícito qué roles existen en el juego

#### Ejemplos:

**Juego con roles específicos (Pictionary):**
```json
{
  "modules": {
    "roles_system": {
      "enabled": true,
      "roles": ["drawer", "guesser"],
      "allow_multiple_roles": false,
      "description": "Sistema de roles: drawer dibuja la palabra, guessers intentan adivinar"
    }
  }
}
```

**Juego sin roles específicos (UNO, Trivia):**
```json
{
  "modules": {
    "roles_system": {
      "enabled": true,
      "roles": ["player"],
      "allow_multiple_roles": false,
      "description": "Todos los jugadores tienen el mismo rol"
    }
  }
}
```

**Juego con roles fijos (Mafia):**
```json
{
  "modules": {
    "roles_system": {
      "enabled": true,
      "roles": ["detective", "mafia", "civil"],
      "allow_multiple_roles": false,
      "description": "Roles asignados al inicio y fijos durante toda la partida"
    }
  }
}
```

### 2. `turn_system` (Recomendado)

Define cómo funcionan los turnos en el juego.

**Modos disponibles:**
- `"sequential"`: Turnos secuenciales uno por uno
- `"simultaneous"`: Todos juegan al mismo tiempo
- `"free"`: Sin turnos, los jugadores actúan libremente

```json
{
  "turn_system": {
    "enabled": true,
    "mode": "sequential",
    "direction": 1
  }
}
```

### 3. `scoring_system` (Recomendado)

Define cómo se manejan las puntuaciones.

```json
{
  "scoring_system": {
    "enabled": true,
    "track_history": true,
    "allow_negative_scores": false
  }
}
```

### 4. `round_system` (Recomendado)

Define cómo funcionan las rondas.

```json
{
  "round_system": {
    "enabled": true,
    "total_rounds": "auto"
  }
}
```

### 5. `timer_system` (Opcional)

Si el juego necesita temporizadores.

```json
{
  "timer_system": {
    "enabled": true
  }
}
```

## Módulos Opcionales

Estos módulos solo se incluyen si el juego los necesita:

- `teams_system`: Para juegos por equipos
- `card_system`: Para juegos de cartas
- `board_system`: Para juegos de tablero
- `guest_system`: Para permitir invitados sin registro
- `spectator_mode`: Para permitir observadores
- `ai_players`: Para bots/IA
- `replay_system`: Para grabar partidas

## Estructura Completa de Ejemplo

```json
{
  "name": "Mi Juego",
  "slug": "mi-juego",
  "min_players": 2,
  "max_players": 8,
  "description": "Descripción del juego",

  "modules": {
    "roles_system": {
      "enabled": true,
      "roles": ["player"],
      "allow_multiple_roles": false,
      "description": "Todos los jugadores tienen el mismo rol"
    },
    "turn_system": {
      "enabled": true,
      "mode": "sequential"
    },
    "scoring_system": {
      "enabled": true,
      "track_history": true,
      "allow_negative_scores": false
    },
    "round_system": {
      "enabled": true,
      "total_rounds": 5
    },
    "timer_system": {
      "enabled": true
    }
  }
}
```

## Validación

El sistema validará que:

1. ✅ `roles_system` esté definido en todos los juegos
2. ✅ `roles_system.roles` contenga al menos un rol
3. ⚠️ Si falta `roles_system`, se emitirá un WARNING en logs

## Ver También

- [Arquitectura Modular](MODULAR_ARCHITECTURE.md)
- [Decisiones Técnicas](TECHNICAL_DECISIONS.md)
- [Sistema de Roles](modules/ROLES_SYSTEM.md)
