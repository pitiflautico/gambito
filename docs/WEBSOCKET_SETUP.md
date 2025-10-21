# 🔌 Configuración de WebSockets - Laravel Reverb

**Estado:** ✅ Funcionando
**Fecha:** 2025-10-21

---

## ✅ Configuración Exitosa

### 1. Variables de Entorno (.env)

```env
# Broadcasting
BROADCAST_CONNECTION=reverb

# Queue (IMPORTANTE: sync en desarrollo para broadcasts inmediatos)
QUEUE_CONNECTION=sync

# Reverb Server
REVERB_APP_ID=groupsgames
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8086
REVERB_SCHEME=http

# Frontend WebSocket
VITE_REVERB_APP_KEY=local-key
VITE_REVERB_HOST=127.0.0.1
VITE_REVERB_PORT=8086
VITE_REVERB_SCHEME=http
```

### 2. Iniciar Servidor Reverb

```bash
php artisan reverb:start --host=127.0.0.1 --port=8086 --debug
```

### 3. Acceder con HTTP (NO HTTPS) en desarrollo

```
http://gambito.test/test-websocket
```

⚠️ **IMPORTANTE:** Usar HTTP para evitar mixed content errors.

---

## 🧪 Testing

### Página de prueba

```
http://gambito.test/test-websocket
```

### Enviar evento de prueba

```bash
php artisan tinker
```

```php
broadcast(new \App\Events\TestEvent('Hola desde WebSocket'));
```

Deberías ver el mensaje aparecer en tiempo real en el navegador.

---

## 📦 Estructura de Eventos

### Crear un Evento

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class TestEvent implements ShouldBroadcast
{
    public string $message;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('test-channel');
    }

    public function broadcastAs(): string
    {
        return 'TestEvent'; // Nombre explícito del evento
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
```

### Escuchar en Frontend

```javascript
// Canal público
window.Echo.channel('test-channel')
    .listen('TestEvent', (data) => {
        console.log('Evento recibido:', data);
    });

// Canal privado (requiere autenticación)
window.Echo.private('room.CODIGO')
    .listen('GameStarted', (data) => {
        console.log('Juego iniciado:', data);
    });
```

---

## 🐛 Troubleshooting

### Error: "WebSocket connection failed"

**Causa:** Reverb no está corriendo o puerto incorrecto.

**Solución:**
```bash
# Verificar si Reverb está corriendo
ps aux | grep reverb

# Reiniciar Reverb
pkill -f reverb:start
php artisan reverb:start --host=127.0.0.1 --port=8086 --debug
```

### Error: "Mixed Content"

**Causa:** Estás accediendo con HTTPS pero WebSocket usa WS (no WSS).

**Solución en desarrollo:**
```bash
# Desactivar HTTPS en Herd/Valet
herd unsecure gambito
# o
valet unsecure gambito

# Acceder con HTTP
http://gambito.test
```

### Eventos no llegan al navegador

**Causa:** Queue está configurada con `database` pero no hay worker corriendo.

**Solución en desarrollo:**
```env
# .env
QUEUE_CONNECTION=sync  # Broadcasts inmediatos
```

**Solución en producción:**
```bash
# Iniciar queue worker
php artisan queue:work
```

---

## 🚀 Producción

Ver configuración SSL completa en: [`INSTALLATION.md`](INSTALLATION.md#configuración-ssl-en-producción)

**Resumen:**
- Usar `wss://` en lugar de `ws://`
- Configurar proxy Nginx con SSL
- Usar puerto 6001 con certificado SSL
- `QUEUE_CONNECTION=redis` con workers

---

## 📚 Referencias

- **Laravel Reverb Docs:** https://reverb.laravel.com
- **Laravel Broadcasting:** https://laravel.com/docs/11.x/broadcasting
- **Laravel Echo:** https://laravel.com/docs/11.x/broadcasting#client-side-installation

---

**Última actualización:** 2025-10-21
**Mantenido por:** Equipo de desarrollo Gambito
