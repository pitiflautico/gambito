# 📚 Documentación del Proyecto Gambito

**Plataforma de Juegos Sociales Modulares**

> ⚠️ **IMPORTANTE:** Antes de trabajar en este proyecto, lee [`INSTRUCTIONS_FOR_AGENTS.md`](INSTRUCTIONS_FOR_AGENTS.md)

---

## 📖 Índice de Documentación

### 🎯 Documentación Esencial (LECTURA OBLIGATORIA)
- 🤖 [**INSTRUCCIONES PARA AGENTES**](INSTRUCTIONS_FOR_AGENTS.md) - **LEE ESTO PRIMERO**
- 🏗️ [**Arquitectura General**](ARCHITECTURE.md) - Visión general del sistema modular
- 📖 [**Glosario de Términos**](GLOSSARY.md) - Definiciones de conceptos clave
- 🛠️ [**Guía de Desarrollo**](DEVELOPMENT_GUIDE.md) - Setup, workflow y convenciones

---

### 🏗️ Arquitectura del Sistema
- [**Sistema Modular**](architecture/MODULAR_SYSTEM.md) - Cómo funciona el sistema de plugins
- [**Game Registry**](architecture/GAME_REGISTRY.md) - Descubrimiento y carga de juegos ✅
- [**Capabilities System**](architecture/CAPABILITIES.md) - Sistema de declaración de dependencias

---

### 🔧 Módulos Core (Siempre Activos)

Estos módulos **siempre están disponibles** para todos los juegos:

| Módulo | Estado | Descripción | Documentación |
|--------|--------|-------------|---------------|
| **Game Core** | ⏳ Pendiente | Motor principal del ciclo de vida del juego | [Ver docs](modules/core/GAME_CORE.md) |
| **Room Manager** | ✅ Implementado | Gestión de salas, códigos y QR | [Ver docs](modules/core/ROOM_MANAGER.md) |
| **Player Session** | ✅ Implementado | Gestión de jugadores invitados | [Ver docs](modules/core/PLAYER_SESSION.md) |
| **Game Registry** | ✅ Implementado | Descubrimiento de juegos | [Ver docs](modules/core/GAME_REGISTRY.md) |

---

### 🎮 Módulos Opcionales (Configurables)

Cada juego declara en `capabilities.json` cuáles de estos módulos necesita:

| Módulo | Prioridad | Descripción | Documentación |
|--------|-----------|-------------|---------------|
| **Guest System** | 🔥 MVP | Jugadores sin registro | [Ver docs](modules/optional/GUEST_SYSTEM.md) |
| **Turn System** | 🔥 MVP | Turnos secuenciales/simultáneos | [Ver docs](modules/optional/TURN_SYSTEM.md) |
| **Round System** | 🔥 MVP | Control de rondas (fijas o configurables) | [Ver docs](modules/optional/ROUND_SYSTEM.md) |
| **Scoring System** | 🔥 MVP | Puntuación y ranking | [Ver docs](modules/optional/SCORING_SYSTEM.md) |
| **Timer System** | 🔥 MVP | Temporizadores y límites de tiempo | [Ver docs](modules/optional/TIMER_SYSTEM.md) |
| **Roles System** | 🔥 MVP | Asignación de roles | [Ver docs](modules/optional/ROLES_SYSTEM.md) |
| **Realtime Sync** | 🔥 MVP | WebSockets para sincronización | [Ver docs](modules/optional/REALTIME_SYNC.md) |
| **Teams System** | ⏳ Post-MVP | Agrupación en equipos | [Ver docs](modules/optional/TEAMS_SYSTEM.md) |
| **Card/Deck System** | ⏳ Post-MVP | Gestión de mazos de cartas | [Ver docs](modules/optional/CARD_SYSTEM.md) |
| **Board/Grid System** | ⏳ Post-MVP | Tableros de juego | [Ver docs](modules/optional/BOARD_SYSTEM.md) |
| **Spectator Mode** | ⏳ Post-MVP | Modo observador | [Ver docs](modules/optional/SPECTATOR_MODE.md) |
| **AI Players** | ⏳ Post-MVP | Bots controlados por IA | [Ver docs](modules/optional/AI_PLAYERS.md) |
| **Replay System** | ⏳ Post-MVP | Grabación y reproducción | [Ver docs](modules/optional/REPLAY_SYSTEM.md) |

**Leyenda:**
- 🔥 MVP = Necesario para Pictionary (primera implementación)
- ⏳ Post-MVP = Para juegos futuros

---

### 🎲 Juegos Implementados

| Juego | Estado | Jugadores | Módulos Usados | Documentación |
|-------|--------|-----------|----------------|---------------|
| **Pictionary** | 🚧 En desarrollo | 3-10 | Guest, Turn, Scoring, Timer, Roles, Realtime | [Ver docs](games/PICTIONARY.md) |
| **UNO** | ⏳ Futuro | 2-10 | Guest, Turn, Scoring, Timer, Card | [Ver docs](games/UNO.md) |
| **Trivia** | ⏳ Futuro | 2-∞ | Guest, Turn, Scoring, Timer, Teams | [Ver docs](games/TRIVIA.md) |

---

### 🔌 API y Contratos

- [**GameEngineInterface**](api/GAME_ENGINE_INTERFACE.md) - Contrato obligatorio para todos los juegos
- [**API Endpoints**](api/ENDPOINTS.md) - Documentación de endpoints REST
- [**WebSocket Events**](api/WEBSOCKET_EVENTS.md) - Eventos en tiempo real

---

### 🧪 Testing

- [**Estrategia de Testing**](testing/TESTING_STRATEGY.md) - Cómo testear módulos y juegos
- [**Guía de Tests**](testing/TESTING_GUIDE.md) - Escribir y ejecutar tests

---

### 📋 Decisiones Arquitectónicas (ADRs)

Registro de decisiones importantes del proyecto:

| ADR | Título | Fecha | Estado |
|-----|--------|-------|--------|
| [ADR-001](decisions/ADR-001-MODULAR_SYSTEM.md) | Sistema Modular vs Monolítico | 2025-10-20 | ✅ Aceptado |
| [ADR-002](decisions/ADR-002-ITERATIVE_DEVELOPMENT.md) | Desarrollo Iterativo (Opción C) | 2025-10-21 | ✅ Aceptado |
| [ADR-003](decisions/ADR-003-WEBSOCKETS_REVERB.md) | WebSockets con Laravel Reverb | 2025-10-20 | ✅ Aceptado |
| [ADR-004](decisions/ADR-004-NO_CHAT_MVP.md) | Sin Chat en MVP | 2025-10-20 | ✅ Aceptado |

---

## 🚀 Inicio Rápido

### Para Desarrolladores/Agentes Nuevos

**PASO 1: Lectura obligatoria** (30 minutos)
1. 🤖 [`INSTRUCTIONS_FOR_AGENTS.md`](INSTRUCTIONS_FOR_AGENTS.md) ← **Empieza aquí**
2. 🏗️ [`ARCHITECTURE.md`](ARCHITECTURE.md)
3. 📖 [`GLOSSARY.md`](GLOSSARY.md)
4. 📋 [`../tasks/tasks-0001-prd-plataforma-juegos-sociales.md`](../tasks/tasks-0001-prd-plataforma-juegos-sociales.md)

**PASO 2: Setup del proyecto**
1. 🛠️ [`DEVELOPMENT_GUIDE.md`](DEVELOPMENT_GUIDE.md)

**PASO 3: Ya puedes empezar a trabajar** 🎉

---

### Para Crear un Nuevo Juego

1. Lee [`api/GAME_ENGINE_INTERFACE.md`](api/GAME_ENGINE_INTERFACE.md)
2. Revisa ejemplo en [`games/PICTIONARY.md`](games/PICTIONARY.md)
3. Consulta [módulos opcionales disponibles](modules/optional/)
4. Usa plantilla [`templates/TEMPLATE_GAME.md`](templates/TEMPLATE_GAME.md)

---

### Para Crear un Nuevo Módulo Opcional

1. Lee [`architecture/MODULAR_SYSTEM.md`](architecture/MODULAR_SYSTEM.md)
2. Define contrato/interface del módulo
3. Implementa el servicio
4. Documenta en `docs/modules/optional/TU_MODULO.md`
5. Usa plantilla [`templates/TEMPLATE_MODULE.md`](templates/TEMPLATE_MODULE.md)

---

## 📝 Convenciones de Documentación

### Todos los documentos deben incluir:

- **Estado:** ✅ Implementado | 🚧 En desarrollo | ⏳ Pendiente
- **Fecha de última actualización**
- **Versión del módulo/juego**
- **Ejemplos de código** cuando sea relevante
- **Links a código fuente** relacionado

### Formato de nombres de archivos:

- **Módulos:** `NOMBRE_MODULO.md` (mayúsculas con guiones bajos)
- **Juegos:** `NOMBRE_JUEGO.md` (mayúsculas)
- **ADRs:** `ADR-NNN-TITULO.md` (número + título)
- **Arquitectura:** `NOMBRE_CONCEPTO.md` (mayúsculas con guiones bajos)

### Idioma:

- ✅ **Toda la documentación en español**
- ✅ **Comentarios en código en español**
- ✅ **Nombres de métodos/clases en inglés** (convención Laravel/PHP)

---

## 🔄 Mantenimiento de Documentación

### ⚠️ CRÍTICO: La documentación DEBE actualizarse junto con el código

Cuando implementes algo, **actualiza la documentación INMEDIATAMENTE**:

| Acción | Documentación a actualizar |
|--------|----------------------------|
| ✅ Nuevo módulo core | `docs/modules/core/NOMBRE.md` + `docs/README.md` |
| ✅ Nuevo módulo opcional | `docs/modules/optional/NOMBRE.md` + `docs/README.md` |
| ✅ Nuevo juego | `docs/games/NOMBRE.md` + `docs/README.md` |
| ✅ Nuevo término | `docs/GLOSSARY.md` |
| ✅ Decisión arquitectónica | `docs/decisions/ADR-NNN-TITULO.md` |
| ✅ Cambio en arquitectura | `docs/ARCHITECTURE.md` + ADR |
| ✅ Tarea completada | `tasks/tasks-*.md` (marcar como ✅) |

Ver detalles en: [`INSTRUCTIONS_FOR_AGENTS.md`](INSTRUCTIONS_FOR_AGENTS.md#-workflow-obligatorio)

---

## 📊 Estado Actual del Proyecto

**Última actualización:** 2025-10-21
**Versión:** MVP 1.0 (En desarrollo)

### ✅ Completado

- [x] Database schema (Games, Rooms, Matches, Players, MatchEvents)
- [x] Core Models (Game, Room, GameMatch, Player, MatchEvent)
- [x] Game Registry System (descubrimiento de juegos)
- [x] Room Manager (crear salas, códigos, QR, lobby)
- [x] Player Session (jugadores invitados, heartbeat)

### 🚧 En Desarrollo

- [ ] Pictionary MVP (iterativo - Opción C)
- [ ] Módulos opcionales para Pictionary (Turn, Scoring, Timer, Roles, Realtime)

### ⏳ Pendiente

- [ ] Admin Panel (Filament Resources)
- [ ] Segundo juego (validación de módulos)
- [ ] Módulos opcionales post-MVP

---

## 📞 Contacto y Contribución

### Para contribuir a la documentación:

1. ✅ Sigue las plantillas en `docs/templates/`
2. ✅ Usa español para toda la documentación
3. ✅ Incluye ejemplos de código
4. ✅ Actualiza `docs/README.md` (este archivo) si añades nuevo documento
5. ✅ Actualiza `docs/GLOSSARY.md` si añades nuevos términos

### Plantillas disponibles:

- [`templates/TEMPLATE_MODULE.md`](templates/TEMPLATE_MODULE.md) - Para nuevos módulos
- [`templates/TEMPLATE_GAME.md`](templates/TEMPLATE_GAME.md) - Para nuevos juegos
- [`templates/TEMPLATE_ADR.md`](templates/TEMPLATE_ADR.md) - Para decisiones arquitectónicas

---

## 🎯 Próximos Pasos

**Inmediatos:**
1. Finalizar documentación de módulos core existentes
2. Crear documentación de módulos opcionales (plantillas)
3. Documentar arquitectura detallada
4. Implementar Pictionary MVP (Opción C iterativa)

**Ver plan completo en:** [`../tasks/tasks-0001-prd-plataforma-juegos-sociales.md`](../tasks/tasks-0001-prd-plataforma-juegos-sociales.md)

---

**Mantenido por:** Todo el equipo de desarrollo
**Licencia:** Privado
