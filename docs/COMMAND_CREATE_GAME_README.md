# Comando `/create-game` - README

## Propósito
Este comando ayuda a crear nuevos juegos de forma interactiva, guiada y sin duplicar código.

## Documentación Requerida

### 1. **Guía Principal** (LEER PRIMERO)
📄 `docs/CREATE_GAME_GUIDE.md`

Contiene:
- 12 preguntas estructuradas para hacer al usuario
- Mapeo de respuestas a módulos
- Templates completos de archivos (Engine, ScoreCalculator, Blade, JS)
- Sistema de validación y permisos
- Plan de tareas fase por fase
- Tests guiados

**Usar para**: Flujo interactivo completo

---

### 2. **Referencia de Módulos** (CONSULTAR SEGÚN NECESIDAD)
📄 `docs/GAME_MODULES_REFERENCE.md`

Contiene:
- Documentación técnica de 13 módulos
- Cuándo usar cada módulo
- Código de ejemplo completo
- Configuración JSON
- Eventos que emiten
- Matriz de compatibilidad

**Usar para**: Detalles técnicos de módulos específicos

---

### 3. **Timer System** (SI TIMER ENABLED)
📄 `docs/TIMER_SYSTEM_INTEGRATION.md`

Contiene:
- Sistema de timers completo
- Hooks de timer expiration
- Server-synced countdown
- Ejemplos de speed bonus

**Usar para**: Implementar timer_system

---

### 4. **Convenciones** (CONSULTAR PARA VALIDAR)
📄 `docs/CONVENTIONS.md`
📄 `docs/GAME_CONFIG_CONVENTIONS.md`
📄 `docs/GAMES_CONVENTION.md`

Contienen:
- Naming conventions
- Estructura de archivos
- Reglas de config.json
- Estándares de código

**Usar para**: Validar que código generado siga convenciones

---

### 5. **Arquitectura Base** (PARA CONTEXTO)
📄 `docs/BASE_ENGINE_CLIENT_DESIGN.md`
📄 `docs/ENGINE_ARCHITECTURE.md`

Contienen:
- Diseño de BaseGameEngine
- Métodos abstractos obligatorios
- Hooks opcionales
- Template Method Pattern

**Usar para**: Entender qué provee BaseGameEngine

---

### 6. **Event System** (PARA WEBSOCKETS)
📄 `docs/EVENT_DRIVEN_GAME_FLOW.md`
📄 `docs/HYBRID_EVENT_STRATEGY.md`

Contienen:
- Eventos disponibles
- Cuándo emitir eventos
- WebSocket broadcasting
- Event-driven patterns

**Usar para**: Implementar comunicación real-time

---

## Flujo del Comando

```
1. Leer CREATE_GAME_GUIDE.md
   ↓
2. Hacer 12 preguntas al usuario
   ↓
3. Mapear respuestas a módulos (usar GAME_MODULES_REFERENCE.md)
   ↓
4. Validar configuración (usar CONVENTIONS.md)
   ↓
5. Generar archivos usando templates
   ↓
6. Validar con checklists
   ↓
7. Crear lista de tareas
   ↓
8. Mostrar next steps
```

---

## Reglas Importantes

### ✅ SÍ hacer:
- Generar estructura completa de archivos
- Usar templates de CREATE_GAME_GUIDE.md
- Añadir TODOs en lugares que requieren lógica específica
- Configurar módulos según respuestas
- Crear lista de tareas fase por fase
- Validar contra convenciones

### ❌ NO hacer:
- Implementar lógica completa del juego
- Duplicar código que ya está en BaseGameEngine
- Modificar archivos del core sin permiso
- Generar código que no sigue convenciones
- Saltarse validaciones

---

## Ejemplo de Uso

```
Usuario: /create-game

Comando:
🎮 ¿Cómo se llama tu juego?
Usuario: Speed Quiz

Comando:
📝 Describe brevemente el juego
Usuario: Quiz de preguntas rápidas con bonus por velocidad

Comando:
🎯 ¿Qué tipo de juego es?
a) Preguntas y Respuestas ← Usuario selecciona
...

[Comando hace todas las preguntas]

Comando:
✅ Configuración completada!

Módulos a usar:
- round_system (10 preguntas)
- scoring_system (puntos + speed bonus)
- timer_system (10s por pregunta)
- player_state_system (locks)
- turn_system (simultaneous)

Generando archivos...
✅ games/speed-quiz/SpeedQuizEngine.php
✅ games/speed-quiz/SpeedQuizScoreCalculator.php
✅ games/speed-quiz/config.json
✅ games/speed-quiz/questions.json
✅ games/speed-quiz/views/game.blade.php
✅ games/speed-quiz/js/SpeedQuizGameClient.js

📋 Lista de tareas creada (ver abajo)

🧪 Ejecutando validaciones...
✅ Sintaxis PHP correcta
✅ JSON válido
✅ Estructura de archivos correcta

✨ ¡Juego creado con éxito!

Next steps:
1. Revisar TODOs en SpeedQuizEngine.php
2. Implementar processRoundAction()
3. Implementar startNewRound()
4. Completar ScoreCalculator
5. Crear UI en game.blade.php
```

---

## Casos Especiales

### Si requiere modificar core:
```
⚠️  ADVERTENCIA: Este juego requiere modificar BaseGameEngine

Archivos a modificar:
- app/Contracts/BaseGameEngine.php
  Razón: Nuevo hook para X

¿Continuar? (sí/no)
```

**Acción**: Pedir permiso explícito y explicar razón.

### Si hay conflictos:
```
⚠️  CONFLICTO DETECTADO:
- turn_system: free
- timer_system: round_duration = 15

Los turnos libres no tienen sentido con timer por ronda.
¿Deseas cambiar configuración? (sí/no)
```

**Acción**: Detectar conflictos usando matriz de compatibilidad.

### Si módulo no implementado:
```
⚠️  MÓDULO NO IMPLEMENTADO:
- card_deck_system

Este módulo aún no está completamente implementado.
Se generará estructura básica con TODOs.

¿Continuar? (sí/no)
```

**Acción**: Advertir y generar esqueleto con TODOs.

---

## Output Esperado

### Archivos Generados:
```
games/{slug}/
├── {GameName}Engine.php       # Con TODOs
├── {GameName}ScoreCalculator.php  # Si scoring
├── config.json                # Configurado
├── questions.json             # Si Q&A
├── rules.json                 # Generado
├── views/
│   └── game.blade.php         # Template
└── js/
    └── {GameName}GameClient.js  # Template
```

### Lista de Tareas:
Markdown con:
- Fase 1: Setup (DONE automáticamente)
- Fase 2: Lógica Core (TODOs marcados)
- Fase 3: Puntuación (si aplica)
- Fase 4: Frontend
- Fase 5: Testing
- Fase 6: Polish

### Next Steps:
```
📝 Próximos pasos:

1. cd games/{slug}
2. Revisar TODOs en {GameName}Engine.php
3. Implementar lógica de processRoundAction()
4. Implementar startNewRound()
5. Completar ScoreCalculator (si aplica)
6. Crear UI en views/game.blade.php
7. Implementar handlers en js/{GameName}GameClient.js
8. php artisan test --filter={GameName}Test

📚 Documentación útil:
- docs/GAME_MODULES_REFERENCE.md (módulos)
- docs/TIMER_SYSTEM_INTEGRATION.md (si timer)
- docs/BASE_ENGINE_CLIENT_DESIGN.md (arquitectura)

🎮 Ejemplo de referencia: games/trivia/
```

---

## Resumen

El comando `/create-game`:
1. Lee `CREATE_GAME_GUIDE.md` como guía principal
2. Consulta `GAME_MODULES_REFERENCE.md` para detalles técnicos
3. Valida contra `CONVENTIONS.md`
4. Genera estructura completa con TODOs
5. Crea lista de tareas fase por fase
6. NO implementa lógica completa
7. SIEMPRE pide permiso para modificar core

**Principio**: Generar esqueleto robusto, desarrollador rellena TODOs.
