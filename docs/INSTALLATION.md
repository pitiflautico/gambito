# 📦 Instalación y Deployment - Gambito

**Guía completa de instalación desde cero hasta producción**

---

## 📋 Índice

1. [Requisitos del Sistema](#requisitos-del-sistema)
2. [Instalación Local (Desarrollo)](#instalación-local-desarrollo)
3. [Configuración de WebSockets](#configuración-de-websockets)
4. [Testing Pre-Deploy](#testing-pre-deploy)
5. [Deployment a Producción](#deployment-a-producción)
6. [Configuración SSL en Producción](#configuración-ssl-en-producción)
7. [Troubleshooting](#troubleshooting)

---

## 🖥️ Requisitos del Sistema

### Desarrollo Local

- **PHP:** >= 8.2
- **Composer:** >= 2.5
- **Node.js:** >= 20.19+ (recomendado: 22.12+)
- **NPM:** >= 10.0
- **MySQL:** >= 8.0
- **Redis:** >= 7.0 (opcional pero recomendado)
- **Laravel Herd** o **Laravel Valet** (para macOS)
- **Git:** Última versión

### Producción

- **PHP:** >= 8.2 con extensiones: `mbstring`, `xml`, `pdo_mysql`, `redis`, `pcntl`, `bcmath`
- **MySQL:** >= 8.0
- **Redis:** >= 7.0 (obligatorio en producción)
- **Nginx:** >= 1.24 o **Apache:** >= 2.4
- **Supervisor:** Para gestionar procesos (Reverb, queues)
- **Certificado SSL:** Válido para el dominio (Let's Encrypt recomendado)
- **Servidor dedicado o VPS** con mínimo:
  - 2 GB RAM
  - 2 CPU cores
  - 20 GB SSD

---

## 🚀 Instalación Local (Desarrollo)

### 1. Clonar el Repositorio

```bash
git clone https://github.com/tu-usuario/gambito.git
cd gambito
```

### 2. Instalar Dependencias PHP

```bash
composer install
```

### 3. Instalar Dependencias JavaScript

```bash
npm install
```

### 4. Configurar el Archivo `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Editar `.env` con tus configuraciones:

```env
APP_NAME=Gambito
APP_ENV=local
APP_DEBUG=true
APP_URL=http://gambito.test  # ⚠️ Usar HTTP en desarrollo

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gambito
DB_USERNAME=root
DB_PASSWORD=

CACHE_STORE=redis
SESSION_DRIVER=database
QUEUE_CONNECTION=redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# WebSockets - Laravel Reverb
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=gambito
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8086
REVERB_SCHEME=http

# Frontend WebSocket Config
VITE_REVERB_APP_KEY=local-key
VITE_REVERB_HOST=127.0.0.1
VITE_REVERB_PORT=8086
VITE_REVERB_SCHEME=http  # ⚠️ HTTP en desarrollo
```

### 5. Crear Base de Datos

```bash
# MySQL CLI
mysql -u root -p
CREATE DATABASE gambito CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```

### 6. Ejecutar Migraciones

```bash
php artisan migrate
```

### 7. Descubrir y Registrar Juegos

```bash
# Descubrir juegos disponibles en games/
php artisan games:discover

# Validar configuración de juegos
php artisan games:validate --all

# Registrar juegos en la base de datos
php artisan games:discover --register
```

### 8. Compilar Assets

```bash
npm run build
```

Para desarrollo con hot-reload:

```bash
npm run dev
```

### 9. Configurar el Dominio Local

#### Con Laravel Herd (macOS - Recomendado)

```bash
# Herd detecta automáticamente el proyecto
# Solo asegúrate de que la carpeta esté en ~/Herd/
# o vincúlala manualmente desde la app de Herd
```

#### Con Laravel Valet (macOS)

```bash
cd /path/to/gambito
valet link gambito
# El sitio estará disponible en http://gambito.test
```

⚠️ **IMPORTANTE:** Para desarrollo, usa **HTTP** (`http://gambito.test`) en lugar de HTTPS. Esto evita problemas de mixed content con WebSockets.

Si Valet fuerza HTTPS:

```bash
valet unsecure gambito
```

### 10. Iniciar Servidor WebSocket (Reverb)

En una terminal separada:

```bash
php artisan reverb:start
```

Deberías ver:

```
INFO  Starting server on 127.0.0.1:8086
```

### 11. Verificar Instalación

Abre en el navegador:

```
http://gambito.test/test-websocket
```

Deberías ver:

```
✓ Page loaded
✓ Echo is defined
✓ Channel subscription created
✓ All setup complete!
```

---

## 🔌 Configuración de WebSockets

### Desarrollo Local

**Opción 1: HTTP (Recomendada para desarrollo)**

- URL del sitio: `http://gambito.test` (sin SSL)
- WebSocket: `ws://127.0.0.1:8086` (sin SSL)
- Sin problemas de mixed content

**Opción 2: HTTPS con Proxy SSL**

Si necesitas HTTPS en desarrollo (para testing de features que requieren HTTPS):

1. Usar el dominio con HTTPS: `https://gambito.test`
2. Configurar proxy Nginx para Reverb (ver sección avanzada)

### Producción

**Configuración obligatoria con SSL:**

Ver sección [Configuración SSL en Producción](#configuración-ssl-en-producción)

---

## ✅ Testing Pre-Deploy

Antes de hacer deploy a producción, verifica:

### 1. Tests Unitarios y Feature

```bash
php artisan test
```

Todos los tests deben pasar (>80% coverage).

### 2. Test de Juegos

```bash
# Validar todos los módulos de juegos
php artisan games:validate --all --verbose

# Verificar que los juegos estén registrados
php artisan tinker
>>> \App\Models\Game::all();
```

### 3. Test de WebSockets

1. Abre `http://gambito.test/test-websocket`
2. Verifica que la conexión se establece
3. En otra terminal, envía un evento de prueba:

```bash
php artisan tinker
>>> broadcast(new \App\Events\Core\GameStarted(1, 'DEMO123'));
```

### 4. Test del Juego Pictionary

```bash
# Abrir demo del canvas
http://gambito.test/pictionary/demo

# Probar como dibujante
http://gambito.test/pictionary/demo?role=drawer

# Probar como adivinador
http://gambito.test/pictionary/demo?role=guesser
```

Verificar:
- ✅ Canvas dibuja correctamente
- ✅ Herramientas funcionan (colores, grosores, borrador)
- ✅ Botón "YO SÉ" funciona
- ✅ Confirmación de respuesta funciona
- ✅ Eliminación de jugadores funciona
- ✅ Timer cuenta regresiva

### 5. Test de Performance

```bash
# Simular 10 jugadores simultáneos
ab -n 100 -c 10 http://gambito.test/rooms
```

---

## 🚢 Deployment a Producción

### Preparación

1. **Servidor:** VPS con Ubuntu 22.04 LTS (recomendado)
2. **Dominio:** Configurado apuntando al servidor
3. **SSL:** Certificado Let's Encrypt

### Paso 1: Configurar el Servidor

```bash
# Conectar al servidor
ssh root@tu-servidor.com

# Actualizar sistema
apt update && apt upgrade -y

# Instalar dependencias
apt install -y nginx mysql-server redis-server supervisor \
    php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis \
    php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip \
    php8.2-bcmath php8.2-pcntl git unzip

# Instalar Node.js 22.x
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt install -y nodejs

# Instalar Composer
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
```

### Paso 2: Clonar Proyecto

```bash
cd /var/www
git clone https://github.com/tu-usuario/gambito.git
cd gambito

# Instalar dependencias
composer install --no-dev --optimize-autoloader
npm install
npm run build

# Permisos
chown -R www-data:www-data /var/www/gambito
chmod -R 755 /var/www/gambito/storage
chmod -R 755 /var/www/gambito/bootstrap/cache
```

### Paso 3: Configurar `.env` de Producción

```bash
cp .env.example .env
nano .env
```

```env
APP_NAME=Gambito
APP_ENV=production
APP_DEBUG=false
APP_URL=https://gambito.com  # ⚠️ HTTPS en producción

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gambito_production
DB_USERNAME=gambito_user
DB_PASSWORD=PASSWORD_SEGURO_AQUI

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# WebSockets con SSL
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=gambito_prod
REVERB_APP_KEY=CLAVE_SEGURA_PRODUCTION
REVERB_APP_SECRET=SECRET_SEGURO_PRODUCTION
REVERB_HOST=0.0.0.0
REVERB_PORT=6001
REVERB_SCHEME=https

VITE_REVERB_APP_KEY=CLAVE_SEGURA_PRODUCTION
VITE_REVERB_HOST=gambito.com
VITE_REVERB_PORT=6001
VITE_REVERB_SCHEME=https  # ⚠️ HTTPS en producción
```

```bash
php artisan key:generate
```

### Paso 4: Configurar MySQL

```bash
mysql -u root -p
```

```sql
CREATE DATABASE gambito_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gambito_user'@'localhost' IDENTIFIED BY 'PASSWORD_SEGURO_AQUI';
GRANT ALL PRIVILEGES ON gambito_production.* TO 'gambito_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

```bash
php artisan migrate --force
php artisan games:discover --register
```

### Paso 5: Configurar Nginx

```bash
nano /etc/nginx/sites-available/gambito
```

```nginx
# Configuración completa en la siguiente sección
```

Habilitar el sitio:

```bash
ln -s /etc/nginx/sites-available/gambito /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx
```

### Paso 6: Configurar SSL con Let's Encrypt

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d gambito.com -d www.gambito.com
```

### Paso 7: Configurar Supervisor (Reverb + Queues)

Ver sección de configuración SSL.

---

## 🔒 Configuración SSL en Producción

### Nginx Configuration (Completa)

`/etc/nginx/sites-available/gambito`:

```nginx
# Redirect HTTP to HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name gambito.com www.gambito.com;
    return 301 https://$server_name$request_uri;
}

# Main Application (HTTPS)
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name gambito.com www.gambito.com;

    root /var/www/gambito/public;
    index index.php index.html;

    # SSL Certificates (Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/gambito.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/gambito.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Laravel
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}

# WebSocket Server (Reverb) con SSL
upstream websocket_backend {
    server 127.0.0.1:8086;
}

server {
    listen 6001 ssl http2;
    listen [::]:6001 ssl http2;
    server_name gambito.com;

    # SSL Certificates (SAME as main app)
    ssl_certificate /etc/letsencrypt/live/gambito.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/gambito.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    location / {
        proxy_pass http://websocket_backend;
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

### Supervisor Configuration

`/etc/supervisor/conf.d/gambito.conf`:

```ini
[program:gambito-reverb]
process_name=%(program_name)s
command=php /var/www/gambito/artisan reverb:start
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/gambito/storage/logs/reverb.log
stopwaitsecs=3600

[program:gambito-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/gambito/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/gambito/storage/logs/queue.log
stopwaitsecs=3600
```

```bash
supervisorctl reread
supervisorctl update
supervisorctl start gambito-reverb:*
supervisorctl start gambito-queue:*
supervisorctl status
```

### Abrir Puerto 6001 en Firewall

```bash
ufw allow 6001/tcp
ufw reload
```

---

## 🐛 Troubleshooting

### WebSocket no conecta

**Síntoma:** Error "WebSocket connection failed"

**Solución:**

1. Verificar que Reverb está corriendo:
   ```bash
   supervisorctl status gambito-reverb
   # o en desarrollo:
   ps aux | grep reverb
   ```

2. Verificar puerto abierto:
   ```bash
   netstat -tlnp | grep 6001
   ```

3. Verificar logs:
   ```bash
   tail -f storage/logs/reverb.log
   ```

4. En desarrollo, usar HTTP:
   ```
   http://gambito.test (NO https://)
   ```

### Mixed Content Error en Producción

**Síntoma:** "Mixed Content: The page at 'https://...' was loaded over HTTPS, but attempted to connect to the insecure WebSocket endpoint 'ws://...'"

**Solución:**

Verificar `.env` de producción:

```env
VITE_REVERB_SCHEME=https  # NO http
VITE_REVERB_HOST=gambito.com  # NO 127.0.0.1
```

Recompilar assets:

```bash
npm run build
php artisan optimize:clear
```

### SSL Certificate Error

**Síntoma:** "ERR_CERT_AUTHORITY_INVALID" en WebSocket

**Solución:**

1. Verificar que el certificado incluye el dominio:
   ```bash
   openssl s_client -connect gambito.com:6001 -servername gambito.com
   ```

2. Renovar certificado si expiró:
   ```bash
   certbot renew
   systemctl reload nginx
   ```

### Reverb no inicia

**Síntoma:** Supervisor marca como FATAL

**Solución:**

1. Ver logs detallados:
   ```bash
   tail -f /var/www/gambito/storage/logs/reverb.log
   ```

2. Verificar permisos:
   ```bash
   chown -R www-data:www-data /var/www/gambito/storage
   ```

3. Ejecutar manualmente para ver errores:
   ```bash
   sudo -u www-data php /var/www/gambito/artisan reverb:start
   ```

---

## 📚 Recursos Adicionales

- **Documentación Laravel Reverb:** https://reverb.laravel.com
- **Laravel Deployment:** https://laravel.com/docs/11.x/deployment
- **Let's Encrypt:** https://letsencrypt.org
- **Nginx WebSocket Proxy:** https://nginx.org/en/docs/http/websocket.html

---

**Última actualización:** 2025-10-21
**Versión del documento:** 1.0
**Mantenido por:** Equipo de Desarrollo Gambito
