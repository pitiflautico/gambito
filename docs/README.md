# Groups Games - Plataforma de Juegos Multijugador

## ¿Qué es Groups Games?

Groups Games es una plataforma web para jugar juegos multijugador en reuniones presenciales. Cada jugador usa su dispositivo móvil para interactuar con el juego mientras mantienen la interacción social cara a cara.

## Concepto

- **Juegos presenciales**: Diseñado para grupos que están físicamente juntos
- **Un dispositivo por jugador**: Cada persona usa su móvil/tablet
- **Sin chat**: Los jugadores hablan cara a cara, no hay chat en la aplicación
- **Sincronización en tiempo real**: Todos ven actualizaciones instantáneas vía WebSockets

## Arquitectura

### Stack Tecnológico

- **Backend**: Laravel 11 (PHP 8.2+)
- **Frontend**: Blade + Vanilla JavaScript
- **WebSockets**: Laravel Reverb (nativo Laravel 11)
- **Base de Datos**: MySQL
- **Assets**: Vite

### Sistema Modular

La plataforma utiliza un sistema de **módulos reutilizables** que los juegos activan según necesiten:

**Módulos Core** (siempre activos):
- `GameEngine`: Ciclo de vida del juego
- `RoomManager`: Gestión de salas y matches

**Módulos Opcionales** (configurables por juego):
- `GuestSystem`: Invitados sin registro
- `RoundSystem`: Sistema de rondas
- `TurnSystem`: Turnos (simultáneos/secuenciales)
- `ScoringSystem`: Puntuación y ranking
- `TimerService`: Temporizadores
- `RolesSystem`: Roles específicos del juego

### Arquitectura de Eventos

- **Eventos genéricos**: El sistema emite eventos estándar para todos los juegos
  - `GameStartedEvent`, `RoundStartedEvent`, `RoundEndedEvent`, `GameFinishedEvent`
  - `PlayerConnectedEvent`, `PlayerActionEvent`, `TurnChangedEvent`

- **Eventos específicos**: Cada juego puede definir sus propios eventos
  - Ejemplo Trivia: `QuestionDisplayedEvent`, `AnswerSubmittedEvent`

## Juegos Disponibles

### Trivia
Juego de preguntas y respuestas en tiempo real.

**Características**:
- 10 rondas de preguntas
- Turnos simultáneos (todos responden a la vez)
- Timer de 15 segundos por pregunta
- Sistema de puntuación

**Estado**: ✅ Funcional

## Flujo de Usuario

1. **Master crea sala**
   - Usuario autenticado va a "Crear Sala"
   - Selecciona juego
   - Se genera código de 6 caracteres (ej: "ABC123")

2. **Jugadores se unen**
   - Ingresan código de sala
   - Usuarios autenticados: entran directamente
   - Invitados: ingresan nombre primero

3. **Lobby**
   - Todos esperan en el lobby
   - Master ve cuántos jugadores hay
   - Master inicia el juego cuando está listo

4. **Juego**
   - Todos juegan en tiempo real
   - Sincronización vía WebSockets
   - Actualizaciones instantáneas

5. **Resultados**
   - Ranking final
   - Estadísticas del juego

## Configuración del Juego

Cada juego tiene su directorio en `games/{slug}/`:

```
games/trivia/
├── TriviaEngine.php       # Lógica del juego
├── TriviaController.php   # Endpoints HTTP
├── config.json           # Configuración de módulos
├── capabilities.json     # Metadata y eventos
├── routes.php           # Rutas específicas
├── views/
│   ├── lobby.blade.php  # Vista del lobby
│   └── game.blade.php   # Vista del juego
└── assets/
    └── questions.json   # Datos del juego
```

## Testing

La plataforma utiliza **tests como contratos**:

1. **RoomCreationFlowTest** (14 tests): Contrato inmutable para creación de salas
2. **LobbyJoinFlowTest** (18 tests): Contrato inmutable para entrada al lobby

**Total: 32 tests - 100% pasando**

Estos tests **no se pueden modificar sin aprobación explícita**.

```bash
# Ejecutar tests
php artisan test

# Tests sin warnings
./test-clean.sh
```

## Debug

Panel de debug para testing de eventos en tiempo real:

```
http://gambito.test/debug/game-events/{roomCode}
```

Permite:
- Ver eventos WebSocket en vivo
- Iniciar/avanzar/finalizar juego manualmente
- Inspeccionar game_state en tiempo real

## Deployment

La aplicación está preparada para desplegarse como:
- **Monolito**: Todo en un servidor
- **Microservicios** (futuro): Módulos separados

### Configuración

```env
# Base de datos
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=groupsgames

# WebSockets (Reverb)
REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

# Broadcasting
BROADCAST_DRIVER=reverb
```

### Comandos Útiles

```bash
# Iniciar Reverb (WebSockets)
php artisan reverb:start

# Registrar juegos
php artisan games:discover

# Limpiar caché
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

## Próximos Pasos

1. ~~Sistema modular completo~~ ✅
2. ~~Tests como contratos~~ ✅
3. ~~Panel de debug~~ ✅
4. Implementar más juegos (usando sistema modular)
5. Mejorar UI/UX
6. Sistema de estadísticas

## Links

- [Convenciones y Modo de Trabajo](./CONVENTIONS.md)
- [Módulos Disponibles](./MODULES.md)
