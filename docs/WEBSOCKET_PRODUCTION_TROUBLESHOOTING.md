# Troubleshooting WebSocket en Producción

**Guía para diagnosticar y solucionar problemas de conexión WebSocket con Laravel Echo y Reverb en producción.**

---

## 🔍 Diagnóstico Rápido

### Síntoma: `window.Echo.connector.pusher.connection.state === 'failed'`

**Pasos de diagnóstico:**

1. **Verificar configuración en consola del navegador:**
   ```javascript
   // Ver configuración de Echo
   console.log(window.Echo.connector.pusher.config);
   
   // Ver estado de conexión
   console.log(window.Echo.connector.pusher.connection.state);
   
   // Ver último error
   console.log(window.Echo.connector.pusher.connection.last_error);
   ```

2. **Verificar variables de entorno compiladas:**
   - Las variables `VITE_REVERB_*` deben estar disponibles en el bundle compilado
   - Si son `undefined`, necesitas recompilar con `npm run build` después de agregarlas al `.env`

---

## ✅ Checklist de Configuración

### 1. Variables de Entorno en `.env` (Producción)

```env
# Backend Reverb
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=tu-app-id
REVERB_APP_KEY=tu-app-key
REVERB_APP_SECRET=tu-app-secret
REVERB_HOST=gambito.nebulio.es  # Dominio público, NO 127.0.0.1
REVERB_PORT=8080  # Puerto donde corre Reverb internamente
REVERB_SCHEME=https

# Frontend (deben coincidir con las de arriba)
VITE_REVERB_APP_KEY=tu-app-key  # DEBE coincidir con REVERB_APP_KEY
VITE_REVERB_HOST=gambito.nebulio.es  # Dominio público
VITE_REVERB_PORT=443  # Puerto público (443 para HTTPS, o el puerto del proxy)
VITE_REVERB_SCHEME=https
# Opcional: Si Nginx hace proxy en /app
VITE_REVERB_PATH=/app
```

**⚠️ IMPORTANTE:**
- `VITE_REVERB_APP_KEY` **DEBE** coincidir exactamente con `REVERB_APP_KEY`
- `VITE_REVERB_HOST` debe ser el dominio público (no `127.0.0.1` o `localhost`)
- Después de cambiar variables `VITE_*`, ejecuta `npm run build`

### 2. Servidor Reverb Corriendo

```bash
# Verificar que Reverb está corriendo
ps aux | grep reverb

# O con Supervisor
supervisorctl status gambito-reverb

# Ver logs de Reverb
tail -f storage/logs/reverb.log
# o
journalctl -u reverb -f
```

**Si no está corriendo:**
```bash
# Iniciar manualmente (para testing)
php artisan reverb:start --host=0.0.0.0 --port=8080 --debug

# O configurar Supervisor (recomendado para producción)
# Ver: docs/INSTALLATION.md
```

### 3. Configuración de Nginx para WebSocket

Hay dos formas comunes de configurar Nginx:

#### Opción A: Proxy en el mismo puerto 443 con path `/app`

```nginx
server {
    listen 443 ssl http2;
    server_name gambito.nebulio.es;

    # ... configuración SSL ...

    # WebSocket proxy para Laravel Reverb
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 86400;
    }
}
```

**Variables de entorno:**
```env
VITE_REVERB_HOST=gambito.nebulio.es
VITE_REVERB_PORT=443
VITE_REVERB_PATH=/app
VITE_REVERB_SCHEME=https
```

#### Opción B: Puerto separado (ej: 6001)

```nginx
# Servidor separado para WebSocket
server {
    listen 6001 ssl http2;
    server_name gambito.nebulio.es;

    # SSL Certificates (mismo certificado que el sitio principal)
    ssl_certificate /etc/letsencrypt/live/gambito.nebulio.es/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/gambito.nebulio.es/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 86400;
    }
}
```

**Variables de entorno:**
```env
VITE_REVERB_HOST=gambito.nebulio.es
VITE_REVERB_PORT=6001
VITE_REVERB_SCHEME=https
# NO usar VITE_REVERB_PATH
```

### 4. Firewall

```bash
# Verificar que el puerto está abierto
sudo ufw status

# Abrir puerto si es necesario (ej: 6001)
sudo ufw allow 6001/tcp
sudo ufw reload
```

### 5. Recompilar Assets

Después de cambiar variables `VITE_*`:

```bash
npm run build
# o en producción
npm ci --production
npm run build
```

---

## 🐛 Errores Comunes y Soluciones

### Error: "WebSocket connection failed"

**Causas posibles:**

1. **Reverb no está corriendo**
   ```bash
   # Verificar
   ps aux | grep reverb
   
   # Iniciar
   php artisan reverb:start --host=0.0.0.0 --port=8080
   ```

2. **Puerto incorrecto**
   - Verifica que `VITE_REVERB_PORT` coincida con el puerto configurado en Nginx
   - Si usas proxy en `/app`, el puerto debe ser `443`
   - Si usas puerto separado, debe ser el puerto configurado (ej: `6001`)

3. **Host incorrecto**
   - `VITE_REVERB_HOST` debe ser el dominio público, no `127.0.0.1`
   - Verifica que el dominio resuelva correctamente

4. **Key no coincide**
   - `VITE_REVERB_APP_KEY` debe ser **exactamente igual** a `REVERB_APP_KEY`
   - Verifica ambos en `.env` y recompila

### Error: "Mixed Content"

**Síntoma:** Error en consola sobre contenido mixto (HTTPS intentando conectar a WS)

**Solución:**
```env
# Asegúrate de usar HTTPS
VITE_REVERB_SCHEME=https
VITE_REVERB_PORT=443  # o el puerto SSL configurado
```

### Error: "SSL certificate problem"

**Síntoma:** Error de certificado SSL en la conexión WebSocket

**Solución:**
1. Verifica que el certificado SSL incluya el dominio
2. Si usas puerto separado, asegúrate de que el certificado esté configurado para ese puerto también
3. Renueva certificado si es necesario: `certbot renew`

### Variables `undefined` en el navegador

**Síntoma:** `import.meta.env.VITE_REVERB_HOST` es `undefined`

**Solución:**
1. Verifica que las variables estén en `.env` con prefijo `VITE_`
2. Recompila: `npm run build`
3. Limpia cache: `php artisan optimize:clear`
4. Verifica que el archivo `.env` esté siendo leído (no `.env.example`)

---

## 🔧 Comandos Útiles para Debugging

### En el servidor:

```bash
# Ver logs de Reverb
tail -f storage/logs/reverb.log

# Ver logs de Nginx
sudo tail -f /var/log/nginx/error.log
sudo tail -f /var/log/nginx/access.log

# Verificar que Reverb está escuchando
netstat -tlnp | grep 8080

# Probar conexión WebSocket manualmente
wscat -c wss://gambito.nebulio.es:6001/app/tu-app-key
```

### En el navegador:

```javascript
// Ver configuración completa
console.log(window.Echo.connector.pusher.config);

// Ver estado de conexión
console.log(window.Echo.connector.pusher.connection.state);

// Ver eventos de conexión
window.Echo.connector.pusher.connection.bind('error', (err) => {
    console.error('Error:', err);
});

// Intentar reconectar manualmente
window.Echo.connector.pusher.connect();
```

---

## 📋 Checklist Final

Antes de reportar un problema, verifica:

- [ ] Variables `VITE_REVERB_*` están en `.env` y coinciden con `REVERB_*`
- [ ] Assets recompilados con `npm run build` después de cambiar variables
- [ ] Servidor Reverb está corriendo (`ps aux | grep reverb`)
- [ ] Nginx configurado correctamente para WebSocket proxy
- [ ] Puerto abierto en firewall
- [ ] Certificado SSL válido y configurado
- [ ] Host es dominio público (no `127.0.0.1`)
- [ ] `VITE_REVERB_APP_KEY` coincide exactamente con `REVERB_APP_KEY`

---

## 📚 Referencias

- [Laravel Reverb Documentation](https://reverb.laravel.com)
- [Laravel Broadcasting](https://laravel.com/docs/11.x/broadcasting)
- [Pusher.js Documentation](https://github.com/pusher/pusher-js)

---

**Última actualización:** 2025-01-XX

