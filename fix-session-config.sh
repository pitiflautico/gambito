#!/bin/bash
# Script para corregir configuración de sesiones en producción

echo "🔧 Corrigiendo configuración de sesiones..."

cd /var/www/gambito || exit 1

# Verificar y corregir SESSION_DOMAIN
if grep -q "SESSION_DOMAIN=.null" .env; then
    echo "❌ SESSION_DOMAIN está en .null, corrigiendo..."
    sed -i.bak "s/SESSION_DOMAIN=.*/SESSION_DOMAIN=null/" .env
    echo "✅ SESSION_DOMAIN corregido a null"
else
    echo "✅ SESSION_DOMAIN está correcto"
fi

# Verificar SESSION_SECURE_COOKIE
if grep -q "SESSION_SECURE_COOKIE=true" .env; then
    echo "✅ SESSION_SECURE_COOKIE está en true (correcto para HTTPS)"
else
    echo "⚠️  SESSION_SECURE_COOKIE no está configurado"
fi

# Limpiar cache de configuración
php artisan config:clear

echo ""
echo "✅ Configuración de sesiones verificada"
echo "💡 Reinicia PHP-FPM si es necesario: sudo systemctl restart php8.2-fpm"

