# 🤖 INSTRUCCIONES PARA AGENTES Y DESARROLLADORES

**⚠️ LECTURA OBLIGATORIA ANTES DE CUALQUIER IMPLEMENTACIÓN**

---

## 🎯 REGLA DE ORO

> **ANTES DE ESCRIBIR UNA LÍNEA DE CÓDIGO:**
> 1. ✅ Lee la documentación existente
> 2. ✅ Verifica si la funcionalidad ya existe
> 3. ✅ Entiende la arquitectura modular
> 4. ✅ Documenta TODO lo que implementes
> 5. ✅ Actualiza la documentación existente si haces cambios

---

## 📚 WORKFLOW OBLIGATORIO

### 1️⃣ ANTES DE IMPLEMENTAR CUALQUIER FUNCIONALIDAD

```bash
# PASO 1: Leer documentación del proyecto
1. Lee: docs/README.md (índice completo)
2. Lee: docs/ARCHITECTURE.md (arquitectura general)
3. Lee: docs/GLOSSARY.md (términos clave)
4. Lee: tasks/tasks-0001-prd-plataforma-juegos-sociales.md (task list actualizado)

# PASO 2: Verificar si ya existe
1. Busca en el código: grep, find, o search en el IDE
2. Revisa: docs/modules/core/ (módulos core ya implementados)
3. Revisa: docs/modules/optional/ (módulos opcionales disponibles)
4. Revisa: docs/decisions/ (decisiones arquitectónicas tomadas)

# PASO 3: Si existe, entiéndela antes de modificar
1. Lee el código existente
2. Lee la documentación del módulo
3. Lee los tests existentes
4. Comprende el propósito antes de cambiar
```

### 2️⃣ AL IMPLEMENTAR NUEVA FUNCIONALIDAD

```bash
# DURANTE LA IMPLEMENTACIÓN
1. Sigue las convenciones del proyecto (ver ARCHITECTURE.md)
2. Escribe tests (Feature + Unit)
3. Comenta el código en español
4. Usa nombres descriptivos en español

# AL TERMINAR LA IMPLEMENTACIÓN
1. Crea/actualiza documentación del módulo en docs/modules/
2. Actualiza docs/README.md si añades nuevo documento
3. Actualiza docs/GLOSSARY.md si añades nuevos términos
4. Actualiza tasks/tasks-*.md marcando tareas completadas
5. Si tomaste una decisión arquitectónica importante, crea ADR en docs/decisions/
```

### 3️⃣ AL MODIFICAR FUNCIONALIDAD EXISTENTE

```bash
# ANTES DE MODIFICAR
1. Lee la documentación del módulo afectado
2. Lee los tests existentes para entender comportamiento
3. Verifica que no rompas dependencias de otros módulos

# DESPUÉS DE MODIFICAR
1. Actualiza la documentación del módulo
2. Actualiza tests si el comportamiento cambió
3. Verifica que todos los tests pasen: php artisan test
4. Documenta el cambio en un ADR si es arquitectónico
```

---

## 📁 ESTRUCTURA DE DOCUMENTACIÓN

### Documentación Core (Siempre actualizada)
```
docs/
├── README.md                          ← Índice maestro de toda la documentación
├── ARCHITECTURE.md                    ← Arquitectura general del sistema
├── GLOSSARY.md                        ← Glosario de términos (actualizar siempre)
├── INSTRUCTIONS_FOR_AGENTS.md         ← Este documento (las reglas)
├── DEVELOPMENT_GUIDE.md               ← Setup y workflow de desarrollo
│
├── architecture/                      ← Arquitectura detallada
│   ├── MODULAR_SYSTEM.md             ← Cómo funciona el sistema modular
│   ├── GAME_REGISTRY.md              ← Sistema de descubrimiento de juegos
│   └── CAPABILITIES.md               ← Sistema de declaración de dependencias
│
├── modules/                           ← Documentación de cada módulo
│   ├── core/                         ← Módulos core (siempre activos)
│   │   ├── GAME_CORE.md
│   │   ├── ROOM_MANAGER.md          ← ✅ YA IMPLEMENTADO
│   │   ├── PLAYER_SESSION.md        ← ✅ YA IMPLEMENTADO
│   │   └── GAME_REGISTRY.md         ← ✅ YA IMPLEMENTADO
│   │
│   └── optional/                     ← Módulos opcionales (configurables)
│       ├── GUEST_SYSTEM.md
│       ├── TURN_SYSTEM.md
│       ├── SCORING_SYSTEM.md
│       ├── TIMER_SYSTEM.md
│       ├── ROLES_SYSTEM.md
│       ├── TEAMS_SYSTEM.md
│       ├── CARD_SYSTEM.md
│       ├── BOARD_SYSTEM.md
│       ├── SPECTATOR_MODE.md
│       ├── AI_PLAYERS.md
│       ├── REPLAY_SYSTEM.md
│       └── REALTIME_SYNC.md
│
├── games/                             ← Documentación de cada juego
│   ├── PICTIONARY.md                 ← MVP - En implementación
│   ├── UNO.md                        ← Futuro
│   └── TRIVIA.md                     ← Futuro
│
├── api/                               ← API y contratos
│   ├── GAME_ENGINE_INTERFACE.md
│   ├── ENDPOINTS.md
│   └── WEBSOCKET_EVENTS.md
│
├── testing/                           ← Estrategia de testing
│   ├── TESTING_STRATEGY.md
│   └── TESTING_GUIDE.md
│
├── decisions/                         ← ADRs (Architecture Decision Records)
│   ├── ADR-001-MODULAR_SYSTEM.md
│   ├── ADR-002-ITERATIVE_DEVELOPMENT.md
│   ├── ADR-003-WEBSOCKETS_REVERB.md
│   └── ADR-004-NO_CHAT_MVP.md
│
└── templates/                         ← Plantillas para nuevos documentos
    ├── TEMPLATE_MODULE.md
    ├── TEMPLATE_GAME.md
    └── TEMPLATE_ADR.md
```

---

## 📝 PLANTILLAS Y FORMATOS

### Documentación de Módulo (Core u Opcional)

Cada módulo DEBE tener un documento en `docs/modules/core/` o `docs/modules/optional/` con esta estructura:

```markdown
# [Nombre del Módulo]

**Estado:** ✅ Implementado | 🚧 En desarrollo | ⏳ Pendiente
**Tipo:** Core (obligatorio) | Opcional (configurable)
**Versión:** X.Y.Z

---

## 📋 Descripción

[Qué hace el módulo - 2-3 líneas]

## 🎯 Cuándo Usarlo

[Casos de uso - cuándo un juego necesita este módulo]

## ⚙️ Configuración

[Cómo se declara en capabilities.json]

```json
{
  "nombre_modulo": {
    "enabled": true,
    "opciones": "..."
  }
}
```

## 🔧 API / Servicios

### Clases principales
- `Clase1` - Descripción
- `Clase2` - Descripción

### Métodos públicos
[Lista de métodos principales con firma y descripción]

## 💡 Ejemplos de Uso

[Código de ejemplo de cómo usar el módulo]

## 🧪 Tests

[Cómo testear el módulo]

## 📦 Dependencias

[Qué otros módulos/librerías necesita]

## 🔗 Referencias

[Links a código, PRD, ADRs relacionados]
```

### Documentación de Juego

Cada juego DEBE tener un documento en `docs/games/` con esta estructura:

```markdown
# [Nombre del Juego]

**Estado:** ✅ Implementado | 🚧 En desarrollo | ⏳ Pendiente
**Versión:** X.Y.Z
**Jugadores:** Min-Max

---

## 📋 Descripción

[Descripción del juego]

## 🎮 Mecánicas

[Cómo se juega]

## 🧩 Módulos Utilizados

[Lista de módulos que requiere según capabilities.json]

## 📂 Estructura de Archivos

[Lista de archivos del juego en games/{nombre}/]

## ⚙️ Configuración

[config.json y capabilities.json del juego]

## 🔧 Motor del Juego

[Descripción de la clase Engine y su lógica]

## 🎨 Vistas

[Blade templates del juego]

## 🧪 Tests

[Cómo testear el juego]
```

### ADR (Architecture Decision Record)

Cuando tomes una **decisión arquitectónica importante**, crea un ADR en `docs/decisions/`:

```markdown
# ADR-XXX: [Título de la Decisión]

**Fecha:** YYYY-MM-DD
**Estado:** Aceptado | Rechazado | Supersedido por ADR-YYY
**Decidido por:** [Nombre]

---

## Contexto

[Por qué necesitamos tomar esta decisión]

## Decisión

[Qué decidimos hacer]

## Opciones Consideradas

### Opción A: [Nombre]
- Pros: ...
- Contras: ...

### Opción B: [Nombre]
- Pros: ...
- Contras: ...

## Razones

[Por qué elegimos esta opción]

## Consecuencias

[Qué implica esta decisión]

## Referencias

[Links a PRD, código, discusiones]
```

---

## ✅ CHECKLIST ANTES DE HACER COMMIT

```bash
□ He leído la documentación relacionada antes de implementar
□ He verificado que la funcionalidad no existe ya
□ He seguido las convenciones del proyecto
□ He escrito tests (Feature + Unit)
□ Todos los tests pasan: php artisan test
□ He documentado el módulo/juego en docs/
□ He actualizado docs/README.md si añadí nuevo documento
□ He actualizado docs/GLOSSARY.md si añadí nuevos términos
□ He actualizado tasks/tasks-*.md marcando completadas
□ He creado ADR si la decisión es arquitectónica
□ El código está comentado en español
□ He revisado que no rompo dependencias de otros módulos
```

---

## 🚨 REGLAS CRÍTICAS

### ❌ NUNCA hagas esto:

1. **NUNCA implementes sin leer la documentación primero**
2. **NUNCA asumas que algo no existe sin buscar antes**
3. **NUNCA modifiques código sin entender qué hace**
4. **NUNCA dejes código sin documentar**
5. **NUNCA ignores los tests fallidos**
6. **NUNCA dupliques funcionalidad existente**
7. **NUNCA rompas la arquitectura modular**

### ✅ SIEMPRE haz esto:

1. **SIEMPRE lee docs/README.md y docs/ARCHITECTURE.md primero**
2. **SIEMPRE verifica si existe antes de crear**
3. **SIEMPRE documenta lo que implementes**
4. **SIEMPRE actualiza la documentación existente si haces cambios**
5. **SIEMPRE escribe tests**
6. **SIEMPRE sigue las convenciones del proyecto**
7. **SIEMPRE actualiza el task list**

---

## 📊 ESTADO ACTUAL DEL PROYECTO

### ✅ Módulos Core Implementados

- **Room Manager** → `docs/modules/core/ROOM_MANAGER.md`
  - RoomController, RoomService
  - Crear salas, códigos únicos, QR codes
  - Lobby con lista de jugadores
  - ✅ Tests: Feature + Unit

- **Player Session** → `docs/modules/core/PLAYER_SESSION.md`
  - PlayerSessionService
  - Gestión de jugadores invitados (guests)
  - Sesiones temporales, heartbeat
  - ✅ Tests: Feature + Unit

- **Game Registry** → `docs/modules/core/GAME_REGISTRY.md`
  - GameRegistry service
  - Descubrimiento de juegos en games/
  - Validación de capabilities.json
  - ✅ Tests: Feature + Unit (14 tests, 46 assertions)

### 🚧 En Desarrollo

- **Pictionary MVP** → `docs/games/PICTIONARY.md`
  - Estado: Diseño arquitectónico completo
  - Próximo paso: Implementación iterativa (Opción C)

### ⏳ Pendientes (Módulos Opcionales)

- Guest System
- Turn System
- Scoring System
- Timer System
- Roles System
- Realtime Sync (WebSockets)
- Teams System (post-MVP)
- Card/Deck System (post-MVP)
- Board/Grid System (post-MVP)
- Spectator Mode (post-MVP)
- AI Players (post-MVP)
- Replay System (post-MVP)

---

## 🔄 PROCESO DE ACTUALIZACIÓN

### Cuando implementes un NUEVO MÓDULO:

1. Crea el código en `app/Modules/{Nombre}/` o `app/Services/Shared/{Nombre}/`
2. Crea documentación en `docs/modules/core/` o `docs/modules/optional/`
3. Actualiza `docs/README.md` añadiendo link al nuevo módulo
4. Actualiza `docs/GLOSSARY.md` con nuevos términos
5. Actualiza `tasks/tasks-*.md` marcando tarea como completada
6. Crea ADR si la implementación implicó decisiones arquitectónicas

### Cuando implementes un NUEVO JUEGO:

1. Crea carpeta `games/{nombre}/`
2. Implementa `{Nombre}Engine.php` con `GameEngineInterface`
3. Crea `config.json` y `capabilities.json`
4. Crea documentación en `docs/games/{NOMBRE}.md`
5. Actualiza `docs/README.md` añadiendo link al juego
6. Actualiza `tasks/tasks-*.md` marcando tarea como completada

### Cuando MODIFIQUES funcionalidad existente:

1. Lee la documentación del módulo/juego afectado
2. Actualiza el código
3. Actualiza los tests
4. Actualiza la documentación del módulo/juego
5. Si cambia la arquitectura, crea/actualiza ADR
6. Actualiza `docs/GLOSSARY.md` si cambian términos

---

## 📞 Preguntas Frecuentes

### ¿Por qué es tan importante documentar?

Porque este proyecto es **modular y complejo**. Sin documentación actualizada:
- ❌ Duplicarás funcionalidad existente
- ❌ Romperás dependencias entre módulos
- ❌ Otros desarrolladores no entenderán tu código
- ❌ Tú mismo olvidarás qué hace tu código en 1 mes

### ¿Cuándo creo un ADR?

Crea un ADR cuando:
- Tomes una decisión que afecta la arquitectura general
- Elijas entre múltiples opciones técnicas importantes
- Decidas NO implementar algo por razones específicas
- Cambies una decisión arquitectónica previa

### ¿Cómo sé si algo ya existe?

1. Lee `docs/README.md` (índice completo)
2. Busca en `docs/modules/core/` y `docs/modules/optional/`
3. Grep en el código: `grep -r "nombre_funcionalidad" app/`
4. Revisa `tasks/tasks-*.md` para ver qué está implementado

### ¿Qué hago si la documentación está desactualizada?

¡Actualízala! Es responsabilidad de todos mantenerla al día.

---

## 🎯 SIGUIENTE PASO

**Antes de continuar con cualquier implementación:**

1. ✅ Lee este documento completo
2. ✅ Lee `docs/README.md`
3. ✅ Lee `docs/ARCHITECTURE.md`
4. ✅ Lee `docs/GLOSSARY.md`
5. ✅ Lee `tasks/tasks-0001-prd-plataforma-juegos-sociales.md`

**Solo entonces estarás listo para implementar.**

---

**Última actualización:** 2025-10-21
**Versión:** 1.0
**Mantenido por:** Todo el equipo de desarrollo
