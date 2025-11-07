#!/bin/bash
# Script de verificación de configuración Reverb para producción

echo "=== Verificación de Configuración Reverb ==="
echo ""

# Verificar variables en .env
echo "1. Verificando variables en .env..."
if grep -q "VITE_REVERB_APP_KEY" .env; then
    VITE_KEY=$(grep "VITE_REVERB_APP_KEY" .env | cut -d '=' -f2)
    REVERB_KEY=$(grep "REVERB_APP_KEY" .env | cut -d '=' -f2)
    
    echo "   ✓ VITE_REVERB_APP_KEY encontrada: ${VITE_KEY:0:10}..."
    echo "   ✓ REVERB_APP_KEY encontrada: ${REVERB_KEY:0:10}..."
    
    if [ "$VITE_KEY" = "$REVERB_KEY" ]; then
        echo "   ✓ Las claves coinciden"
    else
        echo "   ✗ ERROR: Las claves NO coinciden"
        echo "      VITE_REVERB_APP_KEY=$VITE_KEY"
        echo "      REVERB_APP_KEY=$REVERB_KEY"
    fi
else
    echo "   ✗ ERROR: VITE_REVERB_APP_KEY no encontrada en .env"
fi

echo ""
echo "2. Verificando que Reverb esté corriendo..."
if pgrep -f "reverb:start" > /dev/null; then
    echo "   ✓ Reverb está corriendo"
    ps aux | grep reverb | grep -v grep
else
    echo "   ✗ Reverb NO está corriendo"
    echo "   Ejecuta: php artisan reverb:start --host=0.0.0.0 --port=8080"
fi

echo ""
echo "3. Verificando archivos compilados..."
if [ -f "public/build/manifest.json" ]; then
    echo "   ✓ Manifest encontrado"
    LAST_BUILD=$(stat -c %Y public/build/manifest.json 2>/dev/null || stat -f %m public/build/manifest.json 2>/dev/null)
    NOW=$(date +%s)
    AGE=$((NOW - LAST_BUILD))
    
    if [ $AGE -lt 3600 ]; then
        echo "   ✓ Build reciente (hace menos de 1 hora)"
    else
        echo "   ⚠ Build antiguo (hace más de 1 hora)"
        echo "   Ejecuta: npm run build"
    fi
else
    echo "   ✗ No se encontró manifest.json"
    echo "   Ejecuta: npm run build"
fi

echo ""
echo "4. Verificando configuración de broadcasting..."
BROADCAST=$(grep "BROADCAST_CONNECTION" .env | cut -d '=' -f2)
if [ "$BROADCAST" = "reverb" ]; then
    echo "   ✓ BROADCAST_CONNECTION=reverb"
else
    echo "   ✗ BROADCAST_CONNECTION=$BROADCAST (debe ser 'reverb')"
fi

echo ""
echo "=== Fin de verificación ==="
echo ""
echo "Si todo está correcto, ejecuta:"
echo "  1. npm run build"
echo "  2. php artisan config:clear"
echo "  3. php artisan cache:clear"
echo "  4. Recarga la página en el navegador"

