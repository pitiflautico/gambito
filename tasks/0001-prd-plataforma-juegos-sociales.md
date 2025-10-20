# PRD: Plataforma de Juegos Sociales Modulares - Gambito

**Fecha:** 2025-10-20
**Versión:** 1.0
**Autor:** Equipo Gambito
**Estado:** Draft - Pendiente de Implementación

---

## 1. Introducción/Overview

Gambito es una plataforma de juegos sociales modulares diseñada para partidas presenciales donde los jugadores usan sus dispositivos personales. El objetivo principal es facilitar sesiones de juego breves y dinámicas para grupos de 3-10 personas, con énfasis en la interacción social, momentos de tensión y sorpresa.

La plataforma se construye sobre una arquitectura modular que permite cargar diferentes juegos sin modificar el core del sistema. Cada juego define sus propias reglas, roles, fases y mecánicas, mientras que la plataforma proporciona servicios comunes como autenticación, gestión de salas, sincronización en tiempo real, sistema de turnos, roles y puntuación.

### Problema que Resuelve
- **Para organizadores de eventos sociales:** Necesitan herramientas digitales que dinamicen reuniones presenciales sin perder la interacción cara a cara
- **Para grupos de amigos:** Buscan juegos variados y accesibles que no requieran materiales físicos ni preparación compleja
- **Para desarrolladores de juegos:** Necesitan una plataforma base robusta para crear nuevos juegos sin reinventar la infraestructura

### Solución
Una plataforma web con arquitectura modular donde:
1. Un **master/anfitrión** crea y gestiona la sala de juego
2. Los **jugadores** se conectan escaneando un QR o ingresando un código
3. El **sistema** se encarga de la sincronización, turnos, roles y puntuación
4. Cada **juego** es un módulo independiente con sus propias reglas

---

## 2. Goals (Objetivos)

### Objetivos de Negocio
1. Crear una plataforma escalable que soporte múltiples juegos simultáneos
2. Establecer un modelo freemium (juegos básicos gratis, premium de pago)
3. Construir una base de usuarios activos de al menos 1,000 jugadores en 6 meses
4. Permitir monetización futura a través de juegos premium y contenido adicional

### Objetivos Técnicos
1. Desarrollar una arquitectura modular que permita agregar juegos sin modificar el core
2. Implementar sincronización en tiempo real con latencia < 500ms
3. Soportar hasta 50 salas simultáneas con 10 jugadores cada una (500 usuarios concurrentes)
4. Lograr 99% de uptime en producción
5. Mantener el código base organizado y documentado para facilitar desarrollo futuro

### Objetivos de Usuario
1. Permitir que usuarios no técnicos puedan crear y gestionar partidas fácilmente
2. Minimizar la fricción de entrada (no requiere registro para jugadores)
3. Proporcionar una experiencia fluida en dispositivos móviles visualizada desde webview
4. Mantener la jugabilidad dinámica y social, evitando largos tiempos de espera

---

## 3. User Stories

### US-001: Crear Sala de Juego (Master)
**Como** administrador/master de una reunión social
**Quiero** crear una sala de juego seleccionando un juego disponible
**Para que** mis amigos puedan unirse y juguemos juntos

**Criterios de Aceptación:**
- Puedo iniciar sesión como master
- Veo un listado de juegos disponibles
- Puedo seleccionar un juego y ver su descripción, número de jugadores y duración estimada
- Al crear la sala, obtengo un código único y un QR code
- Puedo ver la URL directa para compartir
- La sala queda en estado "esperando jugadores"

### US-002: Unirse a Sala (Jugador)
**Como** jugador invitado
**Quiero** unirme a una sala sin necesidad de crear cuenta
**Para que** pueda empezar a jugar rápidamente

**Criterios de Aceptación:**
- Puedo ingresar un código de sala de 6 caracteres
- Puedo escanear un QR code desde mi móvil
- Puedo acceder directamente mediante un link compartido
- Solo necesito ingresar mi nombre/apodo
- Veo cuántos jugadores hay en la sala
- Veo cuando el master inicia la partida

### US-003: Gestionar Lobby (Master)
**Como** master de la sala
**Quiero** ver quién está conectado y controlar el inicio del juego
**Para que** pueda asegurarme de que todos estén listos antes de empezar

**Criterios de Aceptación:**
- Veo una lista de jugadores conectados en tiempo real
- Puedo expulsar jugadores si es necesario
- Puedo modificar configuraciones básicas del juego (si aplica)
- Puedo iniciar la partida cuando haya suficientes jugadores
- El sistema me indica si el número de jugadores es válido para el juego seleccionado

### US-004: Asignación de Roles (Sistema)
**Como** sistema
**Quiero** asignar roles automáticamente al inicio de la partida
**Para que** cada jugador tenga su rol secreto o público según las reglas del juego

**Criterios de Aceptación:**
- Los roles se asignan aleatoriamente según configuración del juego
- Cada jugador ve solo su rol (si es secreto)
- Los roles públicos son visibles para todos
- El master puede ver todos los roles si la configuración lo permite
- Los roles se guardan en la sesión para toda la partida

### US-005: Jugar Pictionary/Pictureca (MVP)
**Como** jugador en una partida de Pictionary
**Quiero** dibujar o adivinar según mi turno
**Para que** pueda competir con mis amigos y ganar puntos

**Criterios de Aceptación:**
- Cuando es mi turno de dibujar:
  - Recibo una palabra/frase secreta
  - Tengo un canvas donde puedo dibujar con el dedo/mouse
  - Los demás ven mi dibujo en tiempo real
  - Puedo usar herramientas básicas: lápiz, borrador, colores, grosor
  - Veo un temporizador de tiempo restante
- Cuando soy adivinador:
  - Veo el canvas del dibujante en tiempo real
  - Puedo tocar un botón "¡Ya lo sé!" para responder
  - Digo la respuesta en voz alta (presencial)
  - El dibujante confirma si es correcta o no
  - Si fallo, quedo eliminado de esa ronda
  - Si acierto, gano puntos (más puntos si es más rápido)
- El dibujante gana puntos si alguien acierta rápido

### US-006: Sistema de Puntuación
**Como** jugador
**Quiero** ver mi puntuación y ranking en tiempo real
**Para que** pueda saber cómo voy en la competencia

**Criterios de Aceptación:**
- Veo mi puntuación actual durante la partida
- Veo un ranking de todos los jugadores
- El ranking se actualiza automáticamente después de cada ronda
- Al final de la partida veo el resultado final con podio
- Las puntuaciones se calculan según las reglas específicas de cada juego

### US-007: Sincronización en Tiempo Real
**Como** sistema
**Quiero** mantener sincronizado el estado de la partida entre todos los dispositivos
**Para que** todos los jugadores vean lo mismo sin retrasos perceptibles

**Criterios de Aceptación:**
- Los cambios de estado se propagan en menos de 500ms
- Si un jugador se desconecta, los demás son notificados
- Si un jugador se reconecta, recibe el estado actual
- El dibujante y espectadores ven el canvas sincronizado
- Los eventos (respuestas, eliminaciones) se ven en tiempo real

### US-008: Finalizar Partida
**Como** master o sistema
**Quiero** terminar la partida y mostrar resultados finales
**Para que** los jugadores vean quién ganó y puedan decidir si jugar otra vez

**Criterios de Aceptación:**
- La partida termina cuando se cumplen las condiciones de fin del juego
- Se muestra una pantalla de resultados con:
  - Ganador(es)
  - Tabla completa de puntuaciones
  - Estadísticas de la partida
- El master puede reiniciar con el mismo grupo
- El master puede volver al lobby para elegir otro juego
- Los jugadores pueden abandonar la sala

---

## 4. Functional Requirements (Requisitos Funcionales)

### 4.1 Autenticación y Usuarios

**FR-001:** El sistema debe permitir registro e inicio de sesión para usuarios master
- Implementar con Laravel Breeze (ya existente)
- Rol: 'master' para crear salas
- Email y contraseña obligatorios

**FR-002:** El sistema debe permitir acceso sin registro para jugadores invitados
- Solo requiere nombre/apodo
- Genera sesión temporal vinculada a la sala
- La sesión expira al salir de la sala o después de 4 horas de inactividad

**FR-003:** El sistema debe validar nombres de jugadores
- Mínimo 2 caracteres, máximo 20
- No permitir nombres duplicados en la misma sala
- Caracteres permitidos: letras, números, espacios, guiones

### 4.2 Gestión de Salas (Lobby)

**FR-004:** El master debe poder crear una sala de juego
- Seleccionar juego de una lista disponible
- Ver información del juego: nombre, descripción, jugadores (min-max), duración
- Generar código único de 6 caracteres alfanuméricos (ej: "ABC123")
- Generar QR code que contenga la URL de la sala
- Generar URL directa compartible

**FR-005:** El sistema debe gestionar el estado de las salas
- Estados: "esperando", "en_juego", "finalizada"
- Las salas en "esperando" aceptan nuevos jugadores
- Las salas "en_juego" no aceptan nuevos jugadores (opcional: configurar si permite espectadores)
- Las salas "finalizada" no aceptan conexiones

**FR-006:** Los jugadores deben poder unirse a una sala mediante:
- Código de sala de 6 caracteres
- Escaneo de QR code (redirige a URL)
- Link directo (ej: https://gambito.test/sala/ABC123)
- URL amigable: /join/{codigo}

**FR-007:** El sistema debe mostrar lista de jugadores conectados en tiempo real
- Nombre de cada jugador
- Estado: conectado, desconectado
- Timestamp de última actividad
- El master debe ver iconos de conexión en tiempo real

**FR-008:** El master debe poder gestionar jugadores en el lobby
- Expulsar un jugador (lo saca de la sala)
- Bloquear la sala (no acepta más jugadores)
- Ver número actual vs requerido de jugadores
- Ver advertencia si el número de jugadores no cumple con el mínimo del juego

**FR-009:** El master debe poder iniciar la partida
- Solo si hay suficientes jugadores (según configuración del juego)
- Botón de "Iniciar Partida" visible solo para el master
- Al iniciar, la sala cambia a estado "en_juego"
- Todos los jugadores son notificados y redirigidos a la pantalla del juego

### 4.3 Sistema de Juegos Modulares

**FR-010:** El sistema debe cargar juegos de forma modular
- Estructura de carpetas: `games/{nombre-juego}/`
- Archivos requeridos por juego:
  - `config.json` - Configuración básica del juego
  - `rules.json` - Fases, turnos, condiciones de victoria
  - `roles.json` - Roles disponibles (opcional)
  - `assets/` - Recursos (imágenes, palabras, etc.)

**FR-011:** Cada juego debe definir su configuración en `config.json`:
```json
{
  "id": "pictionary",
  "name": "Pictionary",
  "description": "Dibuja y adivina",
  "minPlayers": 3,
  "maxPlayers": 10,
  "estimatedDuration": "15-20 minutos",
  "type": "drawing",
  "isPremium": false,
  "version": "1.0"
}
```

**FR-012:** Cada juego debe definir sus fases en `rules.json`:
```json
{
  "phases": [
    {
      "name": "assignment",
      "type": "automatic",
      "description": "Asignación de turnos"
    },
    {
      "name": "drawing",
      "type": "turn-based",
      "duration": 60,
      "description": "Un jugador dibuja, el resto adivina"
    },
    {
      "name": "scoring",
      "type": "automatic",
      "description": "Cálculo de puntos"
    }
  ],
  "winCondition": "highest_score",
  "rounds": 5
}
```

**FR-013:** El sistema debe validar la estructura de cada juego al cargarlo
- Verificar que existan todos los archivos requeridos
- Validar formato JSON
- Verificar campos obligatorios
- Registrar errores en logs si un juego no es válido

### 4.4 Motor de Juego (Game Engine)

**FR-014:** El sistema debe gestionar el flujo de fases del juego
- Cargar las fases desde `rules.json`
- Ejecutar cada fase en orden
- Manejar transiciones entre fases
- Notificar a todos los jugadores sobre cambio de fase

**FR-015:** El sistema debe gestionar turnos (para juegos por turnos)
- Determinar orden de turnos (aleatorio o según reglas)
- Identificar al jugador actual
- Notificar a todos quién tiene el turno
- Avanzar al siguiente turno según condiciones
- Manejar timeouts si un jugador no actúa

**FR-016:** El sistema debe asignar roles al inicio de la partida
- Leer roles desde `roles.json` del juego
- Asignar aleatoriamente según cantidad de jugadores
- Notificar a cada jugador su rol de forma privada (si aplica)
- Permitir roles públicos si la configuración lo indica

**FR-017:** El sistema debe gestionar eventos del juego
- Votar (si aplica)
- Eliminar jugadores (si aplica)
- Revelar información
- Actualizar estado del juego según eventos
- Notificar a jugadores afectados

**FR-018:** El sistema debe calcular puntuaciones
- Aplicar reglas de puntuación del juego
- Actualizar ranking en tiempo real
- Mantener histórico de puntos por ronda
- Determinar ganador según condición de victoria

### 4.5 Juego MVP: Pictionary/Pictureca

**FR-019:** El sistema debe seleccionar palabras aleatorias para dibujar
- Cargar palabras desde `games/pictionary/assets/words.json`
- Categorías: fácil, medio, difícil (configurable)
- No repetir palabras en la misma partida
- Mostrar la palabra solo al dibujante

**FR-020:** El dibujante debe tener un canvas para dibujar
- Implementar canvas HTML5 con eventos táctiles y mouse
- Herramientas disponibles:
  - Lápiz con grosor ajustable (3 tamaños predefinidos)
  - Borrador
  - Selector de colores (8 colores básicos)
  - Botón "Limpiar todo"
- No incluir texto, figuras predefinidas ni auto-completado

**FR-021:** Los espectadores deben ver el dibujo en tiempo real
- Transmitir trazos del dibujante vía WebSocket
- Renderizar en tiempo real en canvas de espectadores
- Latencia máxima: 500ms
- Sincronizar estado completo al conectarse tarde

**FR-022:** Los espectadores deben poder responder cuando creen saber la respuesta
- Botón "¡Ya lo sé!" visible y destacado
- Al presionar, el juego pausa (opcional)
- El jugador dice la respuesta en voz alta (presencial)
- El dibujante tiene botones: "✓ Correcto" / "✗ Incorrecto"
- Si es incorrecto, el jugador queda eliminado de esa ronda
- Si es correcto, se asignan puntos y termina la ronda

**FR-023:** El sistema debe calcular puntos en Pictionary
- **Adivinador:**
  - Acierta en < 15 seg: 100 puntos
  - Acierta en 15-30 seg: 75 puntos
  - Acierta en 30-45 seg: 50 puntos
  - Acierta en > 45 seg: 25 puntos
- **Dibujante:**
  - Si alguien acierta en < 20 seg: 50 puntos
  - Si alguien acierta en 20-40 seg: 30 puntos
  - Si alguien acierta en > 40 seg: 10 puntos
  - Si nadie acierta: 0 puntos

**FR-024:** El sistema debe manejar el temporizador por turno
- Tiempo por turno: 60 segundos (configurable)
- Mostrar contador en pantalla de dibujante y espectadores
- Al terminar el tiempo sin aciertos, pasa al siguiente turno
- Avisos visuales a los 30, 15 y 5 segundos restantes

### 4.6 Sincronización en Tiempo Real (WebSockets)

**FR-025:** El sistema debe implementar WebSockets con Laravel Reverb
- Configurar Laravel Reverb para WebSockets nativos
- Usar canales privados por sala: `sala.{codigo}`
- Autenticar conexiones de jugadores

**FR-026:** El sistema debe emitir eventos en tiempo real:
- `player.joined` - Jugador se une a la sala
- `player.left` - Jugador abandona la sala
- `game.started` - Partida iniciada
- `game.phase_changed` - Cambio de fase
- `game.turn_changed` - Cambio de turno
- `canvas.draw` - Trazo en el canvas (Pictionary)
- `player.answered` - Jugador intenta responder
- `player.eliminated` - Jugador eliminado de la ronda
- `score.updated` - Actualización de puntuación
- `game.finished` - Partida finalizada

**FR-027:** El sistema debe manejar reconexiones
- Si un jugador se desconecta, mantener su estado por 2 minutos
- Al reconectarse, sincronizar estado actual
- Si el master se desconecta, pausar la partida o asignar control a otro jugador

**FR-028:** El sistema debe implementar heartbeat para detectar desconexiones
- Ping cada 10 segundos desde cliente
- Pong desde servidor
- Si no hay respuesta en 30 segundos, marcar como desconectado

### 4.7 Frontend Web (Responsive)

**FR-029:** El frontend debe ser responsive y funcionar en webview móvil
- Diseño mobile-first
- Compatible con iOS Safari y Android Chrome
- Optimizado para pantallas de 320px a 1920px de ancho
- Sin dependencias de características nativas (por ahora)

**FR-030:** El sistema debe tener rutas específicas para móvil y web
- `/m/sala/{codigo}` - Vista móvil optimizada (para webview)
- `/sala/{codigo}` - Vista web completa
- Redirección automática según User-Agent (opcional)

**FR-031:** El frontend debe incluir estas vistas principales:
- **Home** - Selección: crear sala (master) o unirse (jugador)
- **Login** - Solo para masters
- **Crear Sala** - Selector de juegos y configuración
- **Unirse** - Input de código o escaneo de QR
- **Lobby** - Lista de jugadores esperando
- **Juego** - Interfaz dinámica según juego activo
- **Resultados** - Ranking final y opciones post-partida

**FR-032:** El canvas de dibujo (Pictionary) debe ser táctil y responsive
- Tamaño adaptable según pantalla
- Soporte para touch events (móvil)
- Soporte para mouse events (desktop)
- Trazo suave sin lag perceptible
- Deshacer último trazo (opcional)

### 4.8 Panel de Administración (Filament)

**FR-033:** Los administradores deben poder gestionar juegos desde Filament
- CRUD de juegos (crear, ver, editar, eliminar)
- Subir archivos de configuración (JSON)
- Subir assets (imágenes, archivos de palabras)
- Marcar juegos como premium o gratuitos
- Activar/desactivar juegos

**FR-034:** Los administradores deben poder ver estadísticas de uso
- Salas activas en tiempo real
- Historial de partidas jugadas
- Juegos más populares
- Usuarios registrados y jugadores únicos
- Tiempo promedio de partida por juego

**FR-035:** Los administradores deben poder gestionar usuarios master
- Ver lista de masters registrados
- Cambiar roles (admin/master/user)
- Banear usuarios si es necesario
- Ver historial de salas creadas por master

### 4.9 Persistencia y Base de Datos

**FR-036:** El sistema debe almacenar:
- **Usuarios** - Masters registrados
- **Juegos** - Catálogo de juegos disponibles
- **Salas** - Historial de salas creadas
- **Partidas** - Historial completo de partidas jugadas
- **Puntuaciones** - Scores por jugador por partida
- **Eventos** - Log de eventos importantes por partida

**FR-037:** El sistema debe implementar estos modelos principales:
```php
// app/Models/Game.php
- id, name, slug, description, config (JSON), is_premium, is_active

// app/Models/Room.php
- id, code, game_id, master_id, status, settings (JSON), created_at

// app/Models/Match.php
- id, room_id, started_at, finished_at, winner_id, game_state (JSON)

// app/Models/Player.php (sesión temporal)
- id, match_id, name, role, score, is_connected

// app/Models/MatchEvent.php
- id, match_id, event_type, data (JSON), created_at
```

**FR-038:** El sistema debe permitir consultar historial de partidas
- Ver partidas jugadas por fecha
- Filtrar por juego
- Ver detalles de cada partida: jugadores, puntuaciones, duración
- Exportar datos (CSV) si es admin

---

## 5. Non-Goals (Fuera de Alcance del MVP)

**NG-001:** NO incluir chat de texto entre jugadores
- La comunicación es presencial, verbal

**NG-002:** NO incluir sistema de amigos o perfiles sociales
- No hay listas de amigos ni invitaciones

**NG-003:** NO incluir partidas online (jugadores en diferentes ubicaciones físicas)
- El enfoque es 100% presencial, mismo lugar físico

**NG-004:** NO incluir más de 1 juego en el MVP
- Solo Pictionary/Pictureca para validar la arquitectura

**NG-005:** NO incluir sistema de logros o badges
- No hay gamificación externa al juego mismo

**NG-006:** NO incluir monetización en el MVP
- Aunque la arquitectura esté preparada, no se implementa compra/suscripciones aún

**NG-007:** NO incluir editor visual de juegos
- Los juegos se crean manualmente con JSON por ahora

**NG-008:** NO incluir IA para generar contenido
- Las palabras de Pictionary son un archivo JSON estático

**NG-009:** NO incluir replay o grabación de partidas
- No se graban los dibujos ni acciones en formato reproducible

**NG-010:** NO incluir múltiples idiomas
- Solo español en el MVP

---

## 6. Design Considerations (Consideraciones de Diseño)

### 6.1 Arquitectura del Sistema

```
┌─────────────────────────────────────────────────┐
│                   Frontend Web                   │
│            (Blade + Tailwind + JS)              │
│   - Home / Login                                │
│   - Crear Sala / Unirse                         │
│   - Lobby                                       │
│   - Game UI (dinámica por juego)               │
│   - Resultados                                  │
└────────────┬────────────────────────────────────┘
             │ HTTP + WebSocket (Laravel Reverb)
             │
┌────────────┴────────────────────────────────────┐
│          Backend Laravel 11 (API + WS)          │
│                                                  │
│  ┌─────────────────────────────────────────┐  │
│  │         Core Services                    │  │
│  │  - AuthController (Breeze)              │  │
│  │  - RoomController (CRUD salas)          │  │
│  │  - GameController (cargar juegos)       │  │
│  │  - MatchController (gestión partidas)   │  │
│  │  - WebSocketController (Reverb)         │  │
│  └─────────────────────────────────────────┘  │
│                                                  │
│  ┌─────────────────────────────────────────┐  │
│  │        Game Engine (Motor)               │  │
│  │  - TurnManager                           │  │
│  │  - PhaseManager                          │  │
│  │  - RoleManager                           │  │
│  │  - ScoreManager                          │  │
│  │  - EventManager                          │  │
│  └─────────────────────────────────────────┘  │
│                                                  │
│  ┌─────────────────────────────────────────┐  │
│  │      Game Loader (Módulos)               │  │
│  │  games/                                  │  │
│  │   └── pictionary/                        │  │
│  │        ├── config.json                   │  │
│  │        ├── rules.json                    │  │
│  │        ├── assets/words.json             │  │
│  │        └── PictionaryEngine.php          │  │
│  └─────────────────────────────────────────┘  │
│                                                  │
│  ┌─────────────────────────────────────────┐  │
│  │         Admin Panel (Filament)           │  │
│  │  - UserResource                          │  │
│  │  - GameResource                          │  │
│  │  - RoomResource                          │  │
│  │  - MatchResource (historial)             │  │
│  └─────────────────────────────────────────┘  │
└────────────┬────────────────────────────────────┘
             │
┌────────────┴────────────────────────────────────┐
│              MySQL Database                      │
│  - users, games, rooms, matches,                │
│  - players, match_events                        │
└──────────────────────────────────────────────────┘
```

### 6.2 Flujo de Datos - Pictionary

```
1. Master crea sala
   ↓
2. Sistema genera código y QR
   ↓
3. Jugadores escanean/ingresan código
   ↓
4. WebSocket conecta a cada jugador al canal de la sala
   ↓
5. Master inicia partida
   ↓
6. Sistema asigna turnos aleatorios
   ↓
7. RONDA 1:
   a) Dibujante recibe palabra secreta
   b) Canvas se habilita para dibujante
   c) Espectadores ven canvas en tiempo real
   d) Eventos de dibujo se transmiten vía WS
   e) Espectador presiona "¡Ya lo sé!"
   f) Dibujante confirma respuesta
   g) Sistema calcula puntos
   h) Ranking se actualiza
   ↓
8. RONDA 2-5: Repetir paso 7 con siguiente jugador
   ↓
9. Final: Mostrar ganador y tabla de puntos
   ↓
10. Opciones: Jugar de nuevo / Cambiar juego / Salir
```

### 6.3 UI/UX Guidelines

**Colores:**
- Primario: Azul (#3B82F6) - Para acciones principales
- Secundario: Naranja/Amber (#F59E0B) - Para resaltar estados (ya existente en Filament)
- Éxito: Verde (#10B981) - Para confirmaciones
- Error: Rojo (#EF4444) - Para errores y eliminaciones
- Neutral: Gris (#6B7280) - Para textos secundarios

**Tipografía:**
- Fuente principal: Figtree (ya configurada)
- Tamaños:
  - Títulos: 2xl-4xl
  - Botones: base-lg
  - Texto: sm-base

**Componentes Clave:**
- **Botones grandes y táctiles** (mínimo 44x44px para móvil)
- **Feedback visual inmediato** (loading spinners, checkmarks)
- **Notificaciones no intrusivas** (toast messages)
- **Canvas con controles grandes** para facilitar dibujo en móvil

**Accesibilidad:**
- Contraste mínimo WCAG AA
- Botones con labels claros
- Estados de foco visibles

### 6.4 Estructura de Directorios Propuesta

```
groupsgames/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/                 (Breeze - existente)
│   │   │   ├── RoomController.php    (NUEVO)
│   │   │   ├── GameController.php    (NUEVO)
│   │   │   ├── MatchController.php   (NUEVO)
│   │   │   └── WebSocketController.php (NUEVO)
│   │   └── Middleware/
│   │       └── IsAdmin.php           (existente)
│   ├── Models/
│   │   ├── User.php                  (existente)
│   │   ├── Game.php                  (NUEVO)
│   │   ├── Room.php                  (NUEVO)
│   │   ├── Match.php                 (NUEVO)
│   │   ├── Player.php                (NUEVO)
│   │   └── MatchEvent.php            (NUEVO)
│   ├── Services/                     (NUEVO)
│   │   ├── GameEngine/
│   │   │   ├── TurnManager.php
│   │   │   ├── PhaseManager.php
│   │   │   ├── RoleManager.php
│   │   │   ├── ScoreManager.php
│   │   │   └── EventManager.php
│   │   ├── GameLoader.php
│   │   └── RoomService.php
│   └── Filament/
│       └── Resources/
│           ├── UserResource.php      (existente)
│           ├── GameResource.php      (NUEVO)
│           ├── RoomResource.php      (NUEVO)
│           └── MatchResource.php     (NUEVO)
├── games/                            (NUEVO)
│   └── pictionary/
│       ├── config.json
│       ├── rules.json
│       ├── assets/
│       │   └── words.json
│       └── PictionaryEngine.php
├── resources/
│   ├── views/
│   │   ├── layouts/
│   │   │   └── game.blade.php        (NUEVO)
│   │   ├── rooms/                    (NUEVO)
│   │   │   ├── create.blade.php
│   │   │   ├── join.blade.php
│   │   │   ├── lobby.blade.php
│   │   │   └── show.blade.php
│   │   └── games/                    (NUEVO)
│   │       └── pictionary/
│   │           ├── canvas.blade.php
│   │           └── spectator.blade.php
│   ├── js/
│   │   ├── websocket.js              (NUEVO)
│   │   ├── canvas.js                 (NUEVO)
│   │   └── game-state.js             (NUEVO)
│   └── css/
│       └── games.css                 (NUEVO)
├── database/
│   ├── migrations/
│   │   ├── xxxx_create_games_table.php       (NUEVO)
│   │   ├── xxxx_create_rooms_table.php       (NUEVO)
│   │   ├── xxxx_create_matches_table.php     (NUEVO)
│   │   ├── xxxx_create_players_table.php     (NUEVO)
│   │   └── xxxx_create_match_events_table.php (NUEVO)
│   └── seeders/
│       └── GameSeeder.php            (NUEVO)
├── routes/
│   ├── web.php                       (actualizar)
│   ├── api.php                       (actualizar)
│   └── channels.php                  (NUEVO - WebSocket channels)
└── tests/
    ├── Feature/
    │   ├── RoomTest.php              (NUEVO)
    │   ├── GameEngineTest.php        (NUEVO)
    │   └── PictionaryTest.php        (NUEVO)
    └── Unit/
        ├── TurnManagerTest.php       (NUEVO)
        └── ScoreManagerTest.php      (NUEVO)
```

---

## 7. Technical Considerations (Consideraciones Técnicas)

### 7.1 Backend (Laravel)

**Stack Base:**
- Laravel 11 (ya instalado)
- PHP 8.3
- MySQL para persistencia
- Laravel Reverb para WebSockets
- Laravel Breeze para autenticación (ya instalado)
- Filament 3 para panel admin (ya instalado)

**WebSockets con Laravel Reverb:**
- Reverb es el servidor WebSocket oficial de Laravel (gratis)
- Instalación: `composer require laravel/reverb`
- Configurar en `.env`:
  ```env
  BROADCAST_DRIVER=reverb
  REVERB_APP_ID=gambito
  REVERB_APP_KEY=...
  REVERB_APP_SECRET=...
  REVERB_HOST=127.0.0.1
  REVERB_PORT=8080
  REVERB_SCHEME=http
  ```
- Ejecutar: `php artisan reverb:start`

**Broadcasting (Eventos en Tiempo Real):**
- Crear eventos: `php artisan make:event PlayerJoined`
- Implementar `ShouldBroadcast` interface
- Usar canales privados: `PrivateChannel('sala.' . $roomCode)`
- Cliente escucha eventos con Laravel Echo (JS)

**Autenticación de WebSocket:**
- Usar `/broadcasting/auth` endpoint
- Verificar que el jugador pertenece a la sala
- Token temporal en sesión

**Escalabilidad:**
- Reverb soporta hasta ~1000 conexiones en un solo servidor
- Para MVP (50 salas x 10 jugadores = 500 usuarios) es suficiente
- Futuro: Usar Redis + Pusher para producción escalable

### 7.2 Frontend

**Stack Frontend:**
- Blade templates (ya existente)
- Tailwind CSS 4 (ya configurado)
- JavaScript vanilla para WebSockets y Canvas
- Laravel Echo para cliente WebSocket
- HTML5 Canvas API

**Librerías JavaScript a Instalar:**
```bash
npm install laravel-echo pusher-js
```

**Cliente WebSocket (resources/js/websocket.js):**
```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: false,
    enabledTransports: ['ws', 'wss'],
});
```

**Canvas API:**
- Usar HTML5 Canvas nativo (no librerías adicionales)
- Capturar eventos: `touchstart`, `touchmove`, `touchend` (móvil)
- Capturar eventos: `mousedown`, `mousemove`, `mouseup` (desktop)
- Transmitir solo diferencias (deltas) para optimizar ancho de banda
- Formato de evento de trazo:
  ```javascript
  {
    type: 'draw',
    x: 120,
    y: 340,
    color: '#000000',
    width: 3,
    tool: 'pen' // 'pen' | 'eraser'
  }
  ```

**Gestión de Estado:**
- Usar JavaScript vanilla con objetos de estado
- No usar React/Vue en el MVP para simplificar
- Estado principal: `gameState` (global)
  ```javascript
  const gameState = {
    room: { code, status, players },
    match: { phase, turn, scores },
    player: { id, name, role }
  };
  ```

### 7.3 Base de Datos

**Migraciones a Crear:**

1. **games table:**
```php
Schema::create('games', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->text('description');
    $table->json('config'); // config.json content
    $table->boolean('is_premium')->default(false);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

2. **rooms table:**
```php
Schema::create('rooms', function (Blueprint $table) {
    $table->id();
    $table->string('code', 6)->unique();
    $table->foreignId('game_id')->constrained()->onDelete('cascade');
    $table->foreignId('master_id')->constrained('users')->onDelete('cascade');
    $table->enum('status', ['waiting', 'playing', 'finished'])->default('waiting');
    $table->json('settings')->nullable(); // configuración personalizada
    $table->timestamps();
});
```

3. **matches table:**
```php
Schema::create('matches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('room_id')->constrained()->onDelete('cascade');
    $table->timestamp('started_at')->nullable();
    $table->timestamp('finished_at')->nullable();
    $table->foreignId('winner_id')->nullable()->constrained('players')->nullOnDelete();
    $table->json('game_state'); // estado completo del juego
    $table->timestamps();
});
```

4. **players table:**
```php
Schema::create('players', function (Blueprint $table) {
    $table->id();
    $table->foreignId('match_id')->constrained()->onDelete('cascade');
    $table->string('name');
    $table->string('role')->nullable();
    $table->integer('score')->default(0);
    $table->boolean('is_connected')->default(true);
    $table->timestamp('last_ping')->nullable();
    $table->timestamps();
});
```

5. **match_events table:**
```php
Schema::create('match_events', function (Blueprint $table) {
    $table->id();
    $table->foreignId('match_id')->constrained()->onDelete('cascade');
    $table->string('event_type'); // 'draw', 'answer', 'eliminate', 'score', etc.
    $table->json('data'); // datos del evento
    $table->timestamp('created_at');
});
```

**Índices Importantes:**
- `rooms.code` (unique)
- `rooms.status` (para consultas frecuentes)
- `players.match_id` (relación 1:N)
- `match_events.match_id` (para queries de historial)

### 7.4 Seguridad

**Protección de Rutas:**
- `/admin/*` → Middleware `auth`, `admin` (ya existente)
- `/sala/crear` → Middleware `auth` (solo masters)
- `/sala/{code}` → Validar que la sala existe y está activa
- WebSocket channels → Autorización en `routes/channels.php`

**Validación de Entrada:**
- Código de sala: exactamente 6 caracteres alfanuméricos
- Nombre de jugador: 2-20 caracteres, sin caracteres especiales peligrosos
- Canvas: validar coordenadas dentro del rango del canvas
- Eventos WebSocket: validar origen y permisos

**Rate Limiting:**
- Crear sala: máximo 5 por hora por usuario
- Unirse a sala: máximo 20 intentos por hora por IP
- Eventos de canvas: máximo 100 por segundo (throttle)

**CSRF Protection:**
- Activado por defecto en Laravel
- Excluir rutas de WebSocket de CSRF (broadcasting auth es suficiente)

**XSS Prevention:**
- Sanitizar nombres de jugadores
- Blade automáticamente escapa contenido con `{{ }}`
- Usar `{!! !!}` solo cuando sea estrictamente necesario

### 7.5 Testing

**Tests a Implementar:**

**Feature Tests:**
- `RoomTest.php`:
  - Crear sala requiere autenticación
  - Código generado es único
  - QR se genera correctamente
  - Jugadores pueden unirse con código válido
  - No se puede unir a sala inexistente

- `GameEngineTest.php`:
  - Fases se ejecutan en orden
  - Turnos rotan correctamente
  - Puntuación se calcula bien
  - Condiciones de victoria funcionan

- `PictionaryTest.php`:
  - Dibujante recibe palabra
  - Espectadores ven canvas
  - Respuestas correctas suman puntos
  - Respuestas incorrectas eliminan jugador

**Unit Tests:**
- `TurnManagerTest.php`:
  - Orden de turnos aleatorio
  - Siguiente turno se calcula correctamente
  - Timeouts funcionan

- `ScoreManagerTest.php`:
  - Cálculo de puntos según tiempo
  - Ranking se ordena correctamente
  - Ganador se determina bien

**Comando para ejecutar tests:**
```bash
php artisan test
```

### 7.6 Performance

**Optimizaciones Backend:**
- Caché de configuración de juegos (no leer JSON en cada partida)
- Índices en tablas para queries frecuentes
- Lazy loading de relaciones Eloquent
- Queue jobs para logs y estadísticas (no bloquear gameplay)

**Optimizaciones Frontend:**
- Minificar JS y CSS en producción
- Comprimir imágenes de assets
- Lazy load de componentes no críticos
- Debounce de eventos de canvas (agrupar trazos)

**Optimizaciones WebSocket:**
- Throttle de eventos de canvas (enviar máximo cada 50ms)
- Comprimir mensajes WebSocket si son grandes
- Desconectar jugadores inactivos automáticamente

**Métricas a Monitorear:**
- Latencia de WebSocket (< 500ms)
- Tiempo de carga de página (< 2s)
- Uso de memoria del servidor
- Número de conexiones activas

---

## 8. Success Metrics (Métricas de Éxito)

### 8.1 Métricas de Producto (MVP)

**M-001: Tasa de Finalización de Partidas**
- **Meta:** > 80% de las partidas iniciadas se completan
- **Cómo medir:** `(partidas finalizadas / partidas iniciadas) * 100`
- **Por qué:** Indica que el juego es funcional y engagement es alto

**M-002: Tiempo Promedio de Partida**
- **Meta:** 15-20 minutos (según config de Pictionary)
- **Cómo medir:** Promedio de `finished_at - started_at` en tabla `matches`
- **Por qué:** Valida que la duración es la esperada y no hay bloqueos

**M-003: Latencia de WebSocket**
- **Meta:** < 500ms de promedio, < 1s en p95
- **Cómo medir:** Timestamp de evento enviado vs recibido
- **Por qué:** Crítico para experiencia de tiempo real

**M-004: Tasa de Error en Conexiones**
- **Meta:** < 5% de conexiones fallidas
- **Cómo medir:** `(conexiones fallidas / intentos de conexión) * 100`
- **Por qué:** Asegura que los jugadores pueden unirse sin fricción

**M-005: Jugadores por Sala (Promedio)**
- **Meta:** 5-7 jugadores de promedio
- **Cómo medir:** Promedio de jugadores por partida finalizada
- **Por qué:** Valida que el tamaño de grupo es el esperado

### 8.2 Métricas de Adopción (Post-MVP)

**M-006: Usuarios Master Registrados**
- **Meta:** 50 masters en los primeros 2 meses
- **Cómo medir:** Count en tabla `users` con rol `master`

**M-007: Partidas Jugadas**
- **Meta:** 200 partidas completadas en los primeros 2 meses
- **Cómo medir:** Count en tabla `matches` con `finished_at` no null

**M-008: Tasa de Retención de Masters**
- **Meta:** > 40% de masters crean una segunda sala
- **Cómo medir:** Masters con 2+ salas creadas / Total de masters

**M-009: Jugadores Únicos**
- **Meta:** 300 jugadores únicos en 2 meses
- **Cómo medir:** Count distinct de `players.name` (aproximado, ya que no hay registro)

### 8.3 Métricas Técnicas

**M-010: Uptime del Servidor**
- **Meta:** > 99%
- **Cómo medir:** Monitoreo con UptimeRobot o similar

**M-011: Tiempo de Respuesta API**
- **Meta:** < 200ms para endpoints críticos
- **Cómo medir:** Laravel Telescope o APM

**M-012: Uso de Memoria**
- **Meta:** < 512MB por instancia
- **Cómo medir:** Monitoreo del servidor

---

## 9. Open Questions (Preguntas Abiertas)

**Q-001:** ¿Qué sucede si el master abandona la sala durante una partida?
- **Opciones:**
  - A) La partida se pausa y espera reconexión (2 min)
  - B) Se asigna automáticamente otro jugador como master
  - C) La partida termina y todos son expulsados
- **Decisión pendiente:** A decidir en implementación

**Q-002:** ¿Permitir espectadores en partidas en curso?
- **Opciones:**
  - A) No, solo jugadores desde el inicio
  - B) Sí, pueden entrar pero solo observar
- **Decisión pendiente:** Implementar opción A en MVP, B en futuro

**Q-003:** ¿Cómo manejar trampas en Pictionary (ej: escribir texto)?
- **Opciones:**
  - A) Confianza total, es presencial
  - B) Detección de patrones (muy complejo)
  - C) Botón "Reportar trampa" para otros jugadores
- **Decisión pendiente:** Opción A en MVP (confianza)

**Q-004:** ¿Guardar dibujos de Pictionary para historial?
- **Opciones:**
  - A) No, solo puntajes
  - B) Sí, como imagen PNG
  - C) Sí, como array de trazos (JSON)
- **Decisión pendiente:** Opción A en MVP, revisar B o C en futuro

**Q-005:** ¿Permitir que jugadores cambien de nombre en el lobby?
- **Opciones:**
  - A) No, el nombre es fijo al unirse
  - B) Sí, pueden editarlo antes de iniciar
- **Decisión pendiente:** Implementar B (más flexible)

**Q-006:** ¿Implementar sistema de categorías de palabras en Pictionary?
- **Opciones:**
  - A) Todas las palabras son del mismo nivel
  - B) Fácil, Medio, Difícil con diferentes puntos
- **Decisión pendiente:** Implementar A en MVP, B como mejora

**Q-007:** ¿Límite de tiempo de inactividad antes de expulsar jugador?
- **Opciones:**
  - A) 2 minutos
  - B) 5 minutos
  - C) Configurable por master
- **Decisión pendiente:** Implementar B (5 minutos)

**Q-008:** ¿Permitir reinicio de partida con mismo grupo?
- **Opciones:**
  - A) Sí, botón "Jugar de nuevo" mantiene jugadores
  - B) No, deben crear nueva sala
- **Decisión pendiente:** Implementar A (mejor UX)

---

## 10. Timeline y Fases de Desarrollo

### Fase 1: Infraestructura Core (2-3 semanas)

**Sprint 1.1: Backend Base (1 semana)**
- Modelos: Game, Room, Match, Player, MatchEvent
- Migraciones y seeders
- CRUD de Room (crear, listar, ver)
- Sistema de códigos únicos y QR
- Tests básicos

**Sprint 1.2: WebSockets (1 semana)**
- Instalar y configurar Laravel Reverb
- Eventos: PlayerJoined, PlayerLeft, GameStarted
- Cliente JavaScript con Laravel Echo
- Autenticación de canales
- Tests de conexión y eventos

**Sprint 1.3: Game Engine Core (1 semana)**
- TurnManager, PhaseManager, ScoreManager
- GameLoader para cargar configuración de juegos
- Estructura de carpetas de juegos modulares
- Tests de motor de turnos y fases

### Fase 2: Pictionary MVP (2-3 semanas)

**Sprint 2.1: UI de Salas (1 semana)**
- Vista: crear sala (masters)
- Vista: unirse con código/QR/link
- Vista: lobby con lista de jugadores en tiempo real
- Vista: botón iniciar partida (master)
- Responsive mobile-first

**Sprint 2.2: Canvas y Dibujo (1 semana)**
- Canvas HTML5 con herramientas básicas
- Eventos táctiles y mouse
- Transmisión de trazos vía WebSocket
- Sincronización en tiempo real
- Vista dibujante vs espectadores

**Sprint 2.3: Lógica de Pictionary (1 semana)**
- Carga de palabras aleatorias
- Asignación de turnos
- Botón "¡Ya lo sé!" y confirmación de respuestas
- Cálculo de puntos según tiempo
- Eliminación de jugadores en ronda
- Vista de resultados finales

### Fase 3: Admin Panel y Polish (1 semana)

**Sprint 3.1: Filament Resources**
- GameResource (CRUD juegos)
- RoomResource (ver salas activas e historial)
- MatchResource (historial de partidas)
- Dashboard con estadísticas básicas

**Sprint 3.2: Refinamiento**
- Manejo de errores y edge cases
- Mejoras de UX (loading states, transiciones)
- Optimización de performance
- Documentación de código
- Tests finales end-to-end

### Fase 4: Deploy y Monitoreo (3-5 días)

**Sprint 4.1: Preparación para Producción**
- Configurar servidor (Herd en local, evaluar deploy)
- Variables de entorno para producción
- Minificar assets
- Configurar monitoreo (logs, errores)

**Sprint 4.2: Testing con Usuarios Reales**
- Sesiones de prueba con 5-10 personas
- Recolectar feedback
- Ajustes críticos
- Lanzamiento suave

---

## 11. Dependencias y Prerequisitos

### Dependencias Técnicas

**Backend:**
- Laravel 11 ✅ (ya instalado)
- PHP 8.3 ✅ (ya instalado)
- MySQL ✅ (ya configurado)
- Laravel Breeze ✅ (ya instalado)
- Filament 3 ✅ (ya instalado)
- **Laravel Reverb** ❌ (a instalar)

**Frontend:**
- Tailwind CSS 4 ✅ (ya configurado)
- Vite ✅ (ya configurado)
- **Laravel Echo** ❌ (a instalar)
- **Pusher JS** ❌ (a instalar)

**Infraestructura:**
- Laravel Herd ✅ (ya configurado)
- HTTPS (gambito.test) ✅ (ya configurado)
- GitHub repo ✅ (ya creado)

### Conocimientos Requeridos

**Para el Desarrollador:**
- Laravel avanzado (routing, controllers, models, migrations)
- WebSockets y broadcasting
- JavaScript vanilla (eventos, canvas API)
- Blade templating
- Tailwind CSS responsive design
- Git y GitHub

**Para el Equipo:**
- Diseño de juegos (mecánicas, balance)
- UX para mobile (táctil, gestos)
- Testing manual de sesiones multijugador

---

## 12. Risks and Mitigations (Riesgos y Mitigaciones)

### R-001: Latencia Alta en WebSocket
**Riesgo:** La sincronización en tiempo real puede tener lag > 1s
**Impacto:** Alto - Rompe la experiencia de juego
**Mitigación:**
- Optimizar eventos (throttle, debounce)
- Usar servidor local en MVP para minimizar latencia
- Implementar buffer de eventos
- Comprimir mensajes si son grandes
- Monitorear métricas de latencia desde el inicio

### R-002: Desconexiones Frecuentes
**Riesgo:** Jugadores pierden conexión durante partida
**Impacto:** Medio - Frustrante pero recuperable
**Mitigación:**
- Implementar reconexión automática
- Guardar estado en servidor, no en cliente
- Buffer de 2 minutos antes de expulsar jugador
- Notificar a otros jugadores del estado de desconexión
- Permitir a master pausar partida

### R-003: Complejidad del Game Engine
**Riesgo:** Motor de juegos genérico es muy complejo y demora desarrollo
**Impacto:** Alto - Retrasa MVP
**Mitigación:**
- Empezar con arquitectura simple, refactorizar después
- Implementar solo features necesarias para Pictionary
- No sobre-ingenierizar en MVP
- Priorizar funcionalidad sobre abstracción perfecta
- Iterar basándose en necesidades reales del segundo juego

### R-004: Canvas Performance en Móvil
**Riesgo:** Dibujo en móviles viejos puede ser lento
**Impacto:** Medio - Afecta UX en algunos dispositivos
**Mitigación:**
- Optimizar eventos táctiles (requestAnimationFrame)
- Throttle de envío de trazos (max 50ms)
- Probar en dispositivos de gama baja
- Configurar calidad de trazo adaptable
- Fallback a trazos menos frecuentes si hay lag

### R-005: Escalabilidad Limitada de Reverb
**Riesgo:** Reverb solo soporta ~1000 conexiones simultáneas
**Impacto:** Bajo en MVP, Alto en crecimiento
**Mitigación:**
- Suficiente para MVP (500 usuarios concurrentes)
- Documentar límites conocidos
- Plan B: Migrar a Redis + Pusher si se alcanza límite
- Monitorear conexiones activas constantemente
- Implementar queue de espera si hay saturación

### R-006: Trampas en Juegos Presenciales
**Riesgo:** Jugadores pueden hacer trampa (ej: dibujar texto)
**Impacto:** Bajo - El contexto presencial auto-regula
**Mitigación:**
- Confiar en el contexto social presencial
- Documentar "reglas de la casa"
- En futuro: Botón "Reportar" para estadísticas
- No intentar detección automática en MVP (muy complejo)

### R-007: Adopción Lenta
**Riesgo:** No hay suficientes usuarios para validar el producto
**Impacto:** Alto - No se puede iterar con feedback real
**Mitigación:**
- Lanzar con círculo cercano (20-30 personas)
- Organizar eventos presenciales de testing
- Crear video demo para compartir
- Ofrecer juego gratis en MVP para maximizar pruebas
- Recolectar feedback estructurado (forms post-partida)

---

## 13. Appendix (Apéndices)

### A. Glosario de Términos

- **Master:** Usuario registrado que crea y gestiona una sala de juego
- **Jugador/Player:** Participante de una partida (puede ser invitado sin registro)
- **Sala/Room:** Espacio virtual donde se espera a los jugadores antes de iniciar
- **Partida/Match:** Sesión de juego activa con reglas y objetivos
- **Fase/Phase:** Etapa del juego (ej: asignación, juego, votación, resultados)
- **Turno/Turn:** Momento en que un jugador específico tiene acción principal
- **Rol/Role:** Identidad asignada a un jugador (ej: dibujante, adivinador)
- **Canvas:** Área de dibujo HTML5 donde el dibujante crea su ilustración
- **Trazo/Stroke:** Línea individual dibujada en el canvas
- **WebSocket:** Protocolo de comunicación bidireccional en tiempo real
- **Broadcasting:** Sistema de Laravel para enviar eventos a múltiples clientes
- **Reverb:** Servidor WebSocket oficial de Laravel
- **Echo:** Librería JavaScript cliente para WebSockets de Laravel

### B. Referencias

**Documentación Técnica:**
- Laravel 11: https://laravel.com/docs/11.x
- Laravel Reverb: https://reverb.laravel.com/
- Laravel Broadcasting: https://laravel.com/docs/11.x/broadcasting
- Laravel Echo: https://github.com/laravel/echo
- Filament 3: https://filamentphp.com/docs/3.x
- Tailwind CSS: https://tailwindcss.com/docs
- HTML Canvas API: https://developer.mozilla.org/en-US/docs/Web/API/Canvas_API

**Inspiración de Juegos:**
- Skribbl.io (Pictionary online)
- Gartic Phone (teléfono descompuesto con dibujos)
- Jackbox Games (juegos sociales con dispositivos personales)
- Among Us (roles ocultos y votaciones)

### C. Ejemplos de Configuración de Juego

**games/pictionary/config.json:**
```json
{
  "id": "pictionary",
  "name": "Pictionary",
  "description": "Un jugador dibuja una palabra mientras los demás intentan adivinarla. ¡Sé rápido para ganar más puntos!",
  "minPlayers": 3,
  "maxPlayers": 10,
  "estimatedDuration": "15-20 minutos",
  "type": "drawing",
  "isPremium": false,
  "version": "1.0",
  "author": "Gambito",
  "thumbnail": "/games/pictionary/assets/thumbnail.jpg"
}
```

**games/pictionary/rules.json:**
```json
{
  "phases": [
    {
      "name": "assignment",
      "type": "automatic",
      "duration": 5,
      "description": "Asignando turnos..."
    },
    {
      "name": "drawing",
      "type": "turn-based",
      "duration": 60,
      "description": "¡A dibujar!"
    },
    {
      "name": "scoring",
      "type": "automatic",
      "duration": 5,
      "description": "Calculando puntos..."
    },
    {
      "name": "results",
      "type": "manual",
      "description": "Ronda terminada"
    }
  ],
  "turnOrder": "sequential",
  "rounds": 5,
  "winCondition": {
    "type": "highest_score",
    "minScore": 0
  },
  "scoring": {
    "drawer": {
      "quick_guess": { "maxTime": 20, "points": 50 },
      "medium_guess": { "maxTime": 40, "points": 30 },
      "slow_guess": { "maxTime": 60, "points": 10 },
      "no_guess": { "points": 0 }
    },
    "guesser": {
      "very_quick": { "maxTime": 15, "points": 100 },
      "quick": { "maxTime": 30, "points": 75 },
      "medium": { "maxTime": 45, "points": 50 },
      "slow": { "maxTime": 60, "points": 25 }
    }
  },
  "settings": {
    "allowSkip": false,
    "allowHints": false,
    "canvasTools": ["pen", "eraser", "clear"],
    "colorPalette": ["#000000", "#FF0000", "#00FF00", "#0000FF", "#FFFF00", "#FF00FF", "#00FFFF", "#FFFFFF"]
  }
}
```

**games/pictionary/assets/words.json:**
```json
{
  "easy": [
    "casa", "perro", "sol", "árbol", "pelota", "zapato", "mesa", "silla",
    "libro", "teléfono", "auto", "flor", "pájaro", "pez", "gato"
  ],
  "medium": [
    "bicicleta", "montaña", "guitarra", "computadora", "helicóptero",
    "paraguas", "estrella", "luna", "nube", "arcoíris"
  ],
  "hard": [
    "arquitecto", "dinosaurio", "telescopio", "microscopio", "internet",
    "fantasma", "unicornio", "dragón", "pirámide", "astronauta"
  ]
}
```

### D. Wireframes Básicos (Descripción Textual)

**Pantalla 1: Home**
```
┌─────────────────────────────────────┐
│  🎮 GAMBITO                          │
│                                     │
│  Juegos Sociales Presenciales      │
│                                     │
│  ┌─────────────────────────────┐   │
│  │ 🎯 CREAR SALA (Master)      │   │
│  └─────────────────────────────┘   │
│                                     │
│  ┌─────────────────────────────┐   │
│  │ 🎲 UNIRSE A SALA (Jugador)  │   │
│  └─────────────────────────────┘   │
│                                     │
└─────────────────────────────────────┘
```

**Pantalla 2: Lobby (Esperando Jugadores)**
```
┌─────────────────────────────────────┐
│  ◀ Sala: ABC123                     │
│  📱 Código: ABC123  [QR]  [🔗Link] │
│                                     │
│  👥 Jugadores (4/10)                │
│  ┌─────────────────────────────┐   │
│  │ 🟢 Juan (Master)            │   │
│  │ 🟢 María                    │   │
│  │ 🟢 Pedro                    │   │
│  │ 🟢 Ana                      │   │
│  └─────────────────────────────┘   │
│                                     │
│  ⚙️ Configuración:                  │
│  Juego: Pictionary                  │
│  Rondas: 5                          │
│                                     │
│  [  ▶️ INICIAR PARTIDA  ]  (Master)│
└─────────────────────────────────────┘
```

**Pantalla 3: Pictionary - Dibujante**
```
┌─────────────────────────────────────┐
│  🎨 Tu palabra: PERRO       ⏱️ 0:45│
│                                     │
│  ┌───────────────────────────────┐ │
│  │                               │ │
│  │       [Canvas de Dibujo]      │ │
│  │                               │ │
│  │                               │ │
│  └───────────────────────────────┘ │
│                                     │
│  🖊️ ⚫ 🔴 🟢 🔵 🟡   [Grosor: ●]   │
│  [🗑️ Limpiar]                      │
│                                     │
│  💬 María intenta responder...      │
│  [✓ CORRECTO]  [✗ INCORRECTO]      │
└─────────────────────────────────────┘
```

**Pantalla 4: Pictionary - Espectador**
```
┌─────────────────────────────────────┐
│  👁️ Juan está dibujando...  ⏱️ 0:45│
│                                     │
│  ┌───────────────────────────────┐ │
│  │                               │ │
│  │    [Viendo Canvas en Vivo]    │ │
│  │         🐕 (dibujando)        │ │
│  │                               │ │
│  └───────────────────────────────┘ │
│                                     │
│  [   🙋 ¡YA LO SÉ!   ]            │
│                                     │
│  📊 Ranking:                        │
│  1. María - 175 pts                │
│  2. Tú - 150 pts                   │
│  3. Pedro - 100 pts                │
└─────────────────────────────────────┘
```

**Pantalla 5: Resultados Finales**
```
┌─────────────────────────────────────┐
│  🏆 ¡PARTIDA FINALIZADA!            │
│                                     │
│  🥇 MARÍA - 425 puntos              │
│  🥈 JUAN - 400 puntos               │
│  🥉 PEDRO - 350 puntos              │
│                                     │
│  4. Ana - 300 pts                   │
│  5. Luis - 250 pts                  │
│                                     │
│  [🔄 JUGAR DE NUEVO]                │
│  [🎮 CAMBIAR JUEGO]                 │
│  [🚪 SALIR]                         │
└─────────────────────────────────────┘
```

---

## 14. Conclusión

Este PRD define una plataforma ambiciosa pero alcanzable para juegos sociales modulares. El enfoque en un MVP con Pictionary permitirá validar la arquitectura antes de expandir a más juegos.

**Próximos Pasos Inmediatos:**
1. Revisión y aprobación de este PRD
2. Crear issues/tareas en GitHub para cada sprint
3. Configurar entorno de desarrollo (instalar Reverb, Echo)
4. Iniciar Sprint 1.1: Backend Base

**Criterio de Éxito del MVP:**
- ✅ 5+ salas creadas y completadas con éxito
- ✅ Latencia < 500ms en sincronización
- ✅ 0 errores críticos en producción
- ✅ Feedback positivo de > 80% de testers

---

**Documento creado:** 2025-10-20
**Última actualización:** 2025-10-20
**Próxima revisión:** Al finalizar cada sprint
**Responsable:** Equipo Gambito
