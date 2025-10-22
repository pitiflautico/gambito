# Deployment en Producción - GroupsGames

## 🎯 Stack Tecnológico Requerido

### Backend
- **PHP 8.2+**
- **Laravel 11.x**
- **Composer 2.x**

### Base de Datos
- **MySQL 8.0+** o **MariaDB 10.5+**
  - Soporte para JSON columns
  - Soporte para Foreign Keys

### Cache y Sesiones
- **Redis 7.0+**
  - Para cache de aplicación
  - Para sesiones de usuario
  - Para estado de partidas activas (game_state)
  - Para queue jobs

### WebSockets
- **Laravel Reverb** (incluido en Laravel 11)
  - Puerto: 8080 (configurable)
  - Protocolo: Pusher compatible

### Frontend
- **Node.js 20.x+** (para compilar assets)
- **npm 10.x+**
- **Vite 7.x** (bundler)

### Servidor Web
- **Nginx 1.24+** (recomendado)
  - O **Apache 2.4+** con mod_rewrite

### SSL/TLS
- **Certbot** para Let's Encrypt (HTTPS obligatorio para WebSockets)

---

## 📦 Requisitos del Servidor

### Mínimo (1-10 salas simultáneas)
- **CPU**: 2 cores
- **RAM**: 4 GB
- **Disco**: 20 GB SSD
- **Red**: 100 Mbps

### Recomendado (10-50 salas simultáneas)
- **CPU**: 4 cores
- **RAM**: 8 GB
- **Disco**: 40 GB SSD
- **Red**: 1 Gbps

### Alto Tráfico (50-200 salas simultáneas)
- **CPU**: 8 cores
- **RAM**: 16 GB
- **Disco**: 80 GB SSD
- **Red**: 1 Gbps
- **Redis**: Instancia dedicada

---

## 🔧 Configuración del Servidor

### 1. Instalar Dependencias del Sistema

```bash
# Ubuntu 22.04 / 24.04
sudo apt update && sudo apt upgrade -y

# PHP 8.2 y extensiones
sudo apt install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis \
    php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-bcmath \
    php8.2-intl php8.2-gd php8.2-opcache

# MySQL
sudo apt install -y mysql-server

# Redis
sudo apt install -y redis-server

# Nginx
sudo apt install -y nginx

# Certbot (SSL)
sudo apt install -y certbot python3-certbot-nginx

# Node.js (via nvm recomendado)
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash
source ~/.bashrc
nvm install 20
nvm use 20

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

---

## 🗄️ Configuración de MySQL

### 1. Crear Base de Datos

```bash
sudo mysql -u root -p
```

```sql
CREATE DATABASE groupsgames CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'groupsgames_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';

GRANT ALL PRIVILEGES ON groupsgames.* TO 'groupsgames_user'@'localhost';

FLUSH PRIVILEGES;

EXIT;
```

### 2. Optimizar MySQL para JSON

```bash
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
```

Agregar/modificar:

```ini
[mysqld]
# Optimizaciones para JSON y performance
innodb_buffer_pool_size = 2G          # 50-70% de RAM disponible
innodb_log_file_size = 512M
max_connections = 200
query_cache_type = 0                  # Desactivar query cache (obsoleto en MySQL 8)
```

```bash
sudo systemctl restart mysql
```

---

## 🔴 Configuración de Redis

### 1. Configurar Redis

```bash
sudo nano /etc/redis/redis.conf
```

Modificar:

```conf
# Binding
bind 127.0.0.1

# Memoria máxima
maxmemory 2gb
maxmemory-policy allkeys-lru

# Persistencia (opcional, depende de si quieres recuperación tras reinicio)
save 900 1
save 300 10
save 60 10000

# AOF para mayor durabilidad (opcional)
appendonly yes
appendfsync everysec
```

```bash
sudo systemctl restart redis-server
sudo systemctl enable redis-server
```

### 2. Verificar Redis

```bash
redis-cli ping
# Debe responder: PONG
```

---

## 🌐 Configuración de Nginx

### 1. Crear Virtual Host

```bash
sudo nano /etc/nginx/sites-available/groupsgames
```

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name yourdomain.com www.yourdomain.com;

    # Redirigir todo a HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name yourdomain.com www.yourdomain.com;

    root /var/www/groupsgames/public;
    index index.php index.html;

    # SSL Certificates (Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # Logs
    access_log /var/log/nginx/groupsgames_access.log;
    error_log /var/log/nginx/groupsgames_error.log;

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;

    # Security headers
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

    location ~ /\.(?!well-known).* {
        deny all;
    }

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

```bash
# Habilitar sitio
sudo ln -s /etc/nginx/sites-available/groupsgames /etc/nginx/sites-enabled/

# Verificar configuración
sudo nginx -t

# Reiniciar Nginx
sudo systemctl restart nginx
```

---

## 🔐 Configurar SSL con Let's Encrypt

```bash
# Obtener certificado
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com

# Renovación automática
sudo systemctl enable certbot.timer
sudo systemctl start certbot.timer
```

---

## 📦 Desplegar la Aplicación

### 1. Clonar Repositorio

```bash
cd /var/www
sudo git clone https://github.com/your-repo/groupsgames.git
cd groupsgames

# Permisos
sudo chown -R www-data:www-data /var/www/groupsgames
sudo chmod -R 755 /var/www/groupsgames
sudo chmod -R 775 /var/www/groupsgames/storage
sudo chmod -R 775 /var/www/groupsgames/bootstrap/cache
```

### 2. Instalar Dependencias

```bash
# Composer
composer install --no-dev --optimize-autoloader

# NPM
npm install
npm run build
```

### 3. Configurar .env

```bash
cp .env.example .env
nano .env
```

```env
APP_NAME="GroupsGames"
APP_ENV=production
APP_KEY=                                    # Se genera con php artisan key:generate
APP_DEBUG=false
APP_URL=https://yourdomain.com

LOG_CHANNEL=stack
LOG_LEVEL=error

# Base de Datos
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=groupsgames
DB_USERNAME=groupsgames_user
DB_PASSWORD=STRONG_PASSWORD_HERE

# Redis (Cache, Queue, Sessions)
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

# Broadcasting (Laravel Reverb)
BROADCAST_DRIVER=reverb

REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=yourdomain.com
REVERB_PORT=443
REVERB_SCHEME=https

# Pusher (para Reverb)
PUSHER_APP_ID="${REVERB_APP_ID}"
PUSHER_APP_KEY="${REVERB_APP_KEY}"
PUSHER_APP_SECRET="${REVERB_APP_SECRET}"
PUSHER_HOST="${REVERB_HOST}"
PUSHER_PORT="${REVERB_PORT}"
PUSHER_SCHEME="${REVERB_SCHEME}"
PUSHER_APP_CLUSTER=mt1

# Mail (opcional)
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"
```

### 4. Generar Key y Migrar

```bash
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## 🚀 Configurar Laravel Reverb (WebSockets)

### 1. Crear Servicio Systemd

```bash
sudo nano /etc/systemd/system/reverb.service
```

```ini
[Unit]
Description=Laravel Reverb WebSocket Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/groupsgames
ExecStart=/usr/bin/php /var/www/groupsgames/artisan reverb:start --host=0.0.0.0 --port=8080
Restart=on-failure
RestartSec=5s

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable reverb
sudo systemctl start reverb
sudo systemctl status reverb
```

---

## ⚙️ Configurar Queue Worker

### 1. Crear Servicio Systemd

```bash
sudo nano /etc/systemd/system/queue-worker.service
```

```ini
[Unit]
Description=Laravel Queue Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/groupsgames
ExecStart=/usr/bin/php /var/www/groupsgames/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
Restart=on-failure
RestartSec=5s

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable queue-worker
sudo systemctl start queue-worker
sudo systemctl status queue-worker
```

---

## 🕐 Configurar Cron (Scheduler)

```bash
sudo crontab -e -u www-data
```

Agregar:

```cron
* * * * * cd /var/www/groupsgames && php artisan schedule:run >> /dev/null 2>&1
```

---

## 📊 Monitoreo y Logs

### Ver Logs en Tiempo Real

```bash
# Laravel logs
tail -f /var/www/groupsgames/storage/logs/laravel.log

# Nginx access
tail -f /var/log/nginx/groupsgames_access.log

# Nginx errors
tail -f /var/log/nginx/groupsgames_error.log

# Reverb
sudo journalctl -u reverb -f

# Queue Worker
sudo journalctl -u queue-worker -f
```

### Monitoreo de Redis

```bash
redis-cli
> INFO memory
> DBSIZE
> MONITOR  # Ver comandos en tiempo real
```

---

## 🔄 Actualización de la Aplicación

### Script de Deployment

```bash
#!/bin/bash
# deploy.sh

set -e

echo "🚀 Iniciando deployment..."

# Pull latest code
git pull origin main

# Composer
composer install --no-dev --optimize-autoloader

# NPM
npm install
npm run build

# Laravel
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart services
sudo systemctl restart php8.2-fpm
sudo systemctl restart reverb
sudo systemctl restart queue-worker

echo "✅ Deployment completado"
```

```bash
chmod +x deploy.sh
```

---

## 🧪 Testing en Producción

### 1. Verificar Conexión a BD

```bash
php artisan tinker
>>> DB::connection()->getPdo();
```

### 2. Verificar Redis

```bash
php artisan tinker
>>> Cache::put('test', 'works', 60);
>>> Cache::get('test');
```

### 3. Verificar WebSockets

Abrir DevTools en el navegador y verificar:
- Conexión a `wss://yourdomain.com/app`
- Estado: "connected"

---

## 🛡️ Seguridad

### 1. Firewall (UFW)

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

### 2. Fail2Ban (Protección contra ataques)

```bash
sudo apt install -y fail2ban

sudo nano /etc/fail2ban/jail.local
```

```ini
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5

[nginx-http-auth]
enabled = true

[nginx-noscript]
enabled = true

[nginx-badbots]
enabled = true
```

```bash
sudo systemctl enable fail2ban
sudo systemctl start fail2ban
```

---

## 📈 Optimizaciones de Performance

### 1. PHP-FPM

```bash
sudo nano /etc/php/8.2/fpm/pool.d/www.conf
```

```ini
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
pm.max_requests = 500
```

### 2. OPcache

```bash
sudo nano /etc/php/8.2/fpm/conf.d/10-opcache.ini
```

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0  # En producción, deshabilitar
opcache.revalidate_freq=0
```

### 3. Restart PHP-FPM

```bash
sudo systemctl restart php8.2-fpm
```

---

## 🆘 Troubleshooting

### Problema: WebSockets no conectan

```bash
# Verificar Reverb está corriendo
sudo systemctl status reverb

# Verificar puerto abierto
sudo netstat -tlnp | grep 8080

# Ver logs
sudo journalctl -u reverb -n 50
```

### Problema: Queue no procesa

```bash
# Verificar worker está corriendo
sudo systemctl status queue-worker

# Restart worker
sudo systemctl restart queue-worker

# Ver jobs pendientes en Redis
redis-cli
> LLEN queues:default
```

### Problema: 502 Bad Gateway

```bash
# Verificar PHP-FPM
sudo systemctl status php8.2-fpm

# Ver logs PHP
tail -f /var/log/php8.2-fpm.log

# Restart PHP-FPM
sudo systemctl restart php8.2-fpm
```

---

## ✅ Checklist Final

- [ ] MySQL configurado y accesible
- [ ] Redis funcionando
- [ ] Nginx con SSL (HTTPS)
- [ ] Laravel Reverb corriendo
- [ ] Queue Worker activo
- [ ] Cron configurado
- [ ] Logs accesibles
- [ ] Firewall configurado
- [ ] Backup automático configurado
- [ ] Monitoring configurado (opcional: New Relic, Datadog)

---

**Última actualización:** 2025-10-22

**Versión Laravel:** 11.x

**Versión PHP:** 8.2+
