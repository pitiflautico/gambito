#!/bin/bash

# Script de diagnóstico y corrección para WebSocket en producción
# Ejecutar en el servidor: bash fix-websocket-production.sh

echo "🔧 [FIX] Diagnóstico y corrección de WebSocket en producción"
echo "============================================================"
echo ""

# 1. Verificar QUEUE_CONNECTION
echo "📋 [1/4] Verificando QUEUE_CONNECTION..."
QUEUE_CONN=$(php artisan tinker --execute="echo config('queue.default');" 2>/dev/null | tail -1)

if [ "$QUEUE_CONN" != "sync" ]; then
    echo "❌ PROBLEMA ENCONTRADO: QUEUE_CONNECTION = '$QUEUE_CONN' (debe ser 'sync')"
    echo ""
    echo "💡 Solución:"
    echo "   1. Edita el archivo .env en el servidor"
    echo "   2. Cambia: QUEUE_CONNECTION=$QUEUE_CONN"
    echo "   3. Por: QUEUE_CONNECTION=sync"
    echo "   4. Guarda y ejecuta: php artisan config:clear"
    echo ""
    read -p "¿Quieres que lo corrija automáticamente? (s/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Ss]$ ]]; then
        if [ -f .env ]; then
            sed -i.bak "s/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/" .env
            php artisan config:clear
            echo "✅ QUEUE_CONNECTION cambiado a 'sync'"
        else
            echo "❌ No se encontró archivo .env"
        fi
    fi
else
    echo "✅ QUEUE_CONNECTION está en 'sync'"
fi

# 2. Verificar Reverb
echo ""
echo "📡 [2/4] Verificando Reverb..."
REVERB_PID=$(ps aux | grep -i "reverb:start" | grep -v grep | awk '{print $2}')
if [ -z "$REVERB_PID" ]; then
    echo "❌ Reverb NO está corriendo"
    echo "💡 Ejecuta: php artisan reverb:start --host=0.0.0.0 --port=443 --debug"
else
    echo "✅ Reverb está corriendo (PID: $REVERB_PID)"
fi

# 3. Verificar configuración de broadcasting
echo ""
echo "⚙️ [3/4] Verificando configuración de broadcasting..."
php artisan tinker --execute="
echo 'Broadcast Connection: ' . config('broadcasting.default') . PHP_EOL;
echo 'Reverb Host: ' . config('broadcasting.connections.reverb.options.host') . PHP_EOL;
echo 'Reverb Port: ' . config('broadcasting.connections.reverb.options.port') . PHP_EOL;
echo 'Reverb Scheme: ' . config('broadcasting.connections.reverb.options.scheme') . PHP_EOL;
"

# 4. Test de emisión manual
echo ""
echo "🧪 [4/4] Test de emisión manual..."
read -p "¿Quieres probar emitiendo un evento manualmente? (s/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Ss]$ ]]; then
    read -p "Código de sala para probar: " ROOM_CODE
    if [ ! -z "$ROOM_CODE" ]; then
        echo "📢 Emitiendo evento para sala: $ROOM_CODE"
        php test-emit-countdown.php "$ROOM_CODE"
        echo ""
        echo "💡 Verifica en el cliente si el evento llegó"
    fi
fi

echo ""
echo "✅ Diagnóstico completado"
echo ""
echo "📝 Resumen:"
echo "   - QUEUE_CONNECTION debe ser 'sync' para eventos inmediatos"
echo "   - Reverb debe estar corriendo"
echo "   - Verifica logs: tail -f storage/logs/laravel.log | grep GameCountdownEvent"

