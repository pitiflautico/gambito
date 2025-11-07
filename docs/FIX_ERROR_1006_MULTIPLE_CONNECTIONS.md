# Solución: Error 1006 cuando múltiples jugadores se conectan

## Problema

El error `1006: WebSocket closed abnormally` ocurre cuando un segundo jugador se conecta. La conexión inicial funciona correctamente, pero se cierra cuando hay múltiples conexiones simultáneas.

## Causas Posibles

### 1. Configuración de Nginx insuficiente

El proxy de Nginx necesita configuraciones adicionales para manejar múltiples conexiones WebSocket:

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    
    # WebSocket headers
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    
    # Timeouts importantes para WebSocket
    proxy_read_timeout 86400;      # 24 horas
    proxy_send_timeout 86400;      # 24 horas
    proxy_connect_timeout 60;      # 1 minuto
    
    # Buffering deshabilitado para WebSocket
    proxy_buffering off;
    proxy_cache off;
    
    # Mantener conexiones vivas
    keepalive_timeout 86400;
    keepalive_requests 1000;
}
```

### 2. Límites de conexiones en Reverb

Verifica que Reverb pueda manejar múltiples conexiones. En `config/reverb.php`:

```php
'max_connections' => env('REVERB_APP_MAX_CONNECTIONS', 1000), // Aumentar si es necesario
```

### 3. Límites del sistema operativo

Verifica límites de archivos abiertos:

```bash
ulimit -n
# Si es bajo (ej: 1024), aumentar:
ulimit -n 65536
```

### 4. Verificar logs de Reverb

```bash
tail -f storage/logs/reverb.log
# o
journalctl -u reverb -f
```

Busca errores cuando el segundo jugador se conecta.

## Solución Recomendada

### Paso 1: Actualizar configuración de Nginx

Agrega estas líneas a tu configuración de Nginx en `/etc/nginx/sites-available/tu-sitio`:

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    
    # CRÍTICO: Timeouts largos para WebSocket
    proxy_read_timeout 86400;
    proxy_send_timeout 86400;
    proxy_connect_timeout 60;
    
    # Deshabilitar buffering para WebSocket
    proxy_buffering off;
    proxy_cache off;
}
```

### Paso 2: Reiniciar Nginx

```bash
sudo nginx -t  # Verificar configuración
sudo systemctl restart nginx
```

### Paso 3: Verificar configuración de Reverb

En `.env`:

```env
REVERB_APP_MAX_CONNECTIONS=1000
```

### Paso 4: Reiniciar Reverb

```bash
supervisorctl restart gambito-reverb
# o
pkill -f reverb
php artisan reverb:start --host=0.0.0.0 --port=8080
```

## Verificación

Después de aplicar los cambios:

1. Conecta el primer jugador - debe funcionar
2. Conecta el segundo jugador - la conexión del primero NO debe cerrarse
3. Verifica los logs de Reverb para ver conexiones simultáneas
4. Verifica los logs de Nginx: `tail -f /var/log/nginx/error.log`

## Debugging Adicional

Si el problema persiste, ejecuta en la consola del navegador:

```javascript
// Ver todas las conexiones activas
console.log('Canales:', window.Echo.connector.pusher.channels.channels);

// Monitorear cambios de estado
window.Echo.connector.pusher.connection.bind('state_change', (states) => {
    console.log('Estado cambió:', states.previous, '->', states.current);
});
```

Y en el servidor:

```bash
# Ver conexiones WebSocket activas
netstat -an | grep :8080 | grep ESTABLISHED | wc -l

# Ver procesos de Reverb
ps aux | grep reverb
```

