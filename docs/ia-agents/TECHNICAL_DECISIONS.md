# Decisiones Técnicas - Gambito

## 📋 Resumen Ejecutivo

**Fecha**: 2025-10-20
**Versión**: 1.0

---

## 🎯 Concepto del Producto

**Gambito** es una plataforma de juegos para **reuniones presenciales**.

### Características Clave:
- 🏠 **Presencial**: Los jugadores están físicamente juntos
- 📱 **Multi-dispositivo**: Cada jugador usa su móvil/tablet
- 🎮 **Social**: Interacción cara a cara, no digital
- 🔌 **Sin Chat**: Hablan en persona, no necesitan chat de texto

### Ejemplos de Uso:
- Amigos jugando UNO en una fiesta
- Familia jugando Trivia en la sala
- Compañeros de trabajo en team building
- Alumnos en clase con juegos educativos

---

## ⚙️ Decisiones Técnicas

### 1. **Arquitectura: Modular con Plugins**

**Decisión**: Arquitectura de plugins donde cada juego es independiente y configura qué módulos necesita.

```
games/
├── uno/
│   ├── config.json          # Configuración: qué módulos activar
│   ├── GameController.php   # Lógica específica del juego
│   └── views/               # UI del juego
├── trivia/
└── pictionary/
```

**Razón**:
- ✅ Juegos se desarrollan independientemente
- ✅ Solo se cargan features necesarias (performance)
- ✅ Fácil añadir nuevos juegos
- ✅ Cada juego puede ser tan simple o complejo como necesite

---

### 2. **WebSockets: Laravel Reverb**

**Decisión**: Usar Laravel Reverb para sincronización en tiempo real.

**Razón**:
- ✅ Nativo de Laravel 11 (integración perfecta)
- ✅ Gratuito y open-source
- ✅ Broadcasting simplificado con Blade Echo
- ✅ Compatible con Pusher (migración fácil si es necesario)

**Implementación**:
```bash
php artisan install:broadcasting
```

**Preparado para escalar**: Reverb puede moverse a servidor separado sin cambiar código de la app.

---

### 3. **Configuración: Híbrida (JSON + Base de Datos)**

**Decisión**: Configuración base en archivos JSON, overrides en base de datos.

**Estructura**:
```
games/uno/config.json  →  Defaults (versionado en Git)
                       ↓
game_configurations    →  Overrides por instalación (BD)
                       ↓
Redis Cache            →  Config final mergeada (1h TTL)
```

**Flujo**:
1. Leer `games/{slug}/config.json` (defaults)
2. Buscar overrides en tabla `game_configurations`
3. Mergear: `config_db` sobrescribe `config_json`
4. Cachear en Redis

**Razón**:
- ✅ Defaults versionados en Git
- ✅ Admins pueden ajustar sin tocar código
- ✅ Performance (Redis cache)
- ✅ Rollback fácil (borrar override → vuelve a default)

**Ejemplo**:
```php
// Default en config.json
{
  "max_players": 10,
  "turn_timeout": 60
}

// Admin cambia en panel:
max_players = 8

// Resultado final:
{
  "max_players": 8,      // ← override BD
  "turn_timeout": 60     // ← default JSON
}
```

---

### 4. **Deployment: Monolito preparado para Microservicios**

**Decisión**: Empezar con monolito, diseñar para separación futura.

**Arquitectura Actual**:
```
┌─────────────────────────────────┐
│   Laravel 11 (Monolito)         │
│                                 │
│   ┌─────────────────────────┐  │
│   │ HTTP Controllers        │  │
│   │ Game Modules            │  │
│   │ Business Logic          │  │
│   └─────────────────────────┘  │
│                                 │
│   ┌─────────────────────────┐  │
│   │ Laravel Reverb          │  │
│   │ (WebSockets interno)    │  │
│   └─────────────────────────┘  │
└─────────────────────────────────┘
         ↓
    MySQL + Redis
```

**Futuro (si es necesario)**:
```
┌────────────────┐    ┌─────────────────┐
│  Laravel App   │◄──►│  Reverb Server  │
│  (HTTP/API)    │    │  (WebSockets)   │
└────────────────┘    └─────────────────┘
        ↓
   ┌────┴──────┬──────────┐
   │           │          │
 Redis       MySQL     Queue
```

**Principios de diseño**:
- Módulos usan **Interfaces** (no implementaciones)
- Comunicación via **Events** (fácil pasar a Queue)
- Sin dependencias circulares
- Config centralizada

**Razón**:
- ✅ Desarrollo rápido inicial
- ✅ Menos complejidad operacional
- ✅ Fácil debugging
- ✅ Preparado para escalar cuando sea necesario

---

### 5. **Sin Chat**

**Decisión**: **NO implementar sistema de chat**.

**Razón**:
- Los jugadores están físicamente juntos
- Hablan cara a cara
- Chat sería redundante y distractor

**Alternativa** (si se solicita):
- Sistema de emojis/reacciones para feedback visual rápido
- Notificaciones push para eventos importantes
- Sonidos/vibraciones para alertas

---

## 🎮 Módulos del Sistema

### CORE (Siempre Activos):
1. **Game Core** - Ciclo de vida del juego
2. **Room Manager** - Gestión de salas

### Módulos Opcionales (Configurables por juego):
3. **Guest System** - Invitados sin registro
4. **Turn System** - Turnos secuenciales/libres
5. **Scoring System** - Puntuación y ranking
6. **Teams System** - Agrupación en equipos
7. **Timer System** - Temporizadores y timeouts
8. **Roles System** - Roles específicos (dibujante, etc.)
9. **Card/Deck System** - Mazos de cartas
10. **Board/Grid System** - Tableros de juego
11. **Spectator Mode** - Observadores sin jugar
12. **AI Players** - Bots/IA
13. **Replay/History** - Grabación de partidas
14. **Real-time Sync** - Sincronización con Reverb

**ELIMINADO**:
- ~~Chat System~~ - No necesario (juegos presenciales)

---

## 📊 Ejemplo de Configuración

### UNO (Multijugador con turnos)
```json
{
  "name": "UNO",
  "slug": "uno",
  "min_players": 2,
  "max_players": 10,

  "modules": {
    "guests": { "enabled": true },
    "turns": { "enabled": true, "mode": "sequential" },
    "realtime": { "enabled": true, "driver": "reverb" },
    "scoring": { "enabled": true, "mode": "points" },
    "deck": { "enabled": true, "deck_type": "uno" },
    "timer": { "enabled": true, "turn_duration": 30 }
  }
}
```

### Trivia (Todos simultáneos)
```json
{
  "name": "Trivia",
  "slug": "trivia",
  "min_players": 2,
  "max_players": 50,

  "modules": {
    "guests": { "enabled": true },
    "turns": { "enabled": false },      // ← Todos a la vez
    "realtime": { "enabled": true },
    "scoring": { "enabled": true },
    "timer": { "enabled": true, "action_duration": 15 },
    "spectators": { "enabled": true }
  }
}
```

---

## 🚀 Plan de Implementación

### **Fase 1: Base Modular** (Actual)
1. ✅ Sistema de salas y lobby
2. ⏳ Sistema de carga de módulos (`ModuleLoader`)
3. ⏳ Interfaces de módulos (`ModuleInterface`)
4. ⏳ Migrar sistema actual a arquitectura modular

### **Fase 2: Módulos Esenciales**
5. ⏳ Guest Module (ya tenemos base)
6. ⏳ Turn Module
7. ⏳ Scoring Module
8. ⏳ Timer Module
9. ⏳ Laravel Reverb setup

### **Fase 3: Primer Juego**
10. ⏳ Crear juego ejemplo ("Adivina el Número")
11. ⏳ Testing completo del flujo
12. ⏳ Documentación para crear nuevos juegos

### **Fase 4: Juegos Completos**
13. ⏳ UNO
14. ⏳ Trivia
15. ⏳ Pictionary

---

## 📝 Stack Tecnológico Final

### Backend:
- **Framework**: Laravel 11
- **WebSockets**: Laravel Reverb
- **Database**: MySQL 8
- **Cache**: Redis
- **Queue**: Redis + Horizon

### Frontend:
- **Views**: Blade Templates
- **CSS**: Tailwind CSS
- **JS**: Alpine.js + Laravel Echo
- **Build**: Vite

### DevOps:
- **Server**: Laravel Herd (development)
- **Production**: TBD (Laravel Forge / Docker)
- **CI/CD**: TBD

---

## 🎯 Métricas de Éxito

### Performance:
- ⚡ Latencia WebSocket < 100ms
- 📊 Soporte para 50+ jugadores simultáneos por sala
- 💾 Cache hit rate > 90%

### Developer Experience:
- ⏱️ Crear nuevo juego básico en < 2 horas
- 📚 Documentación completa de cada módulo
- 🧪 Tests automatizados > 80% coverage

### User Experience:
- 📱 Responsive en móviles
- 🎨 UI clara y simple
- ⚡ Carga < 2 segundos

---

**Última actualización**: 2025-10-20
**Próxima revisión**: Después de implementar Fase 2
