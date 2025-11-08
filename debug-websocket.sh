#!/bin/bash

# Script de debugging para WebSockets en producción
# Ejecutar en el servidor: bash debug-websocket.sh

echo "🔍 [DEBUG] Iniciando debugging de WebSockets..."
echo ""

# 1. Verificar que Reverb esté corriendo
echo "📡 [DEBUG] Verificando Reverb..."
REVERB_PID=$(ps aux | grep -i reverb | grep -v grep | awk '{print $2}')
if [ -z "$REVERB_PID" ]; then
    echo "❌ Reverb NO está corriendo"
else
    echo "✅ Reverb está corriendo (PID: $REVERB_PID)"
fi
echo ""

# 2. Verificar conexiones WebSocket activas
echo "🔌 [DEBUG] Conexiones WebSocket activas en puerto 443:"
netstat -an | grep :443 | grep ESTABLISHED | wc -l
echo ""

# 3. Ver logs recientes de Laravel relacionados con eventos
echo "📋 [DEBUG] Últimos logs de GameCountdownEvent (últimas 20 líneas):"
tail -n 20 storage/logs/laravel.log | grep -E "(GameCountdownEvent|game.countdown|Transition|apiReady)" || echo "No se encontraron logs recientes"
echo ""

# 4. Verificar configuración de Reverb
echo "⚙️ [DEBUG] Configuración de Reverb:"
if [ -f .env ]; then
    echo "REVERB_HOST: $(grep REVERB_HOST .env | cut -d '=' -f2)"
    echo "REVERB_PORT: $(grep REVERB_PORT .env | cut -d '=' -f2)"
    echo "REVERB_SCHEME: $(grep REVERB_SCHEME .env | cut -d '=' -f2)"
    echo "BROADCAST_CONNECTION: $(grep BROADCAST_CONNECTION .env | cut -d '=' -f2)"
fi
echo ""

# 5. Verificar permisos de broadcasting
echo "🔐 [DEBUG] Verificando permisos de broadcasting..."
php artisan tinker --execute="echo 'Broadcast driver: ' . config('broadcasting.default') . PHP_EOL;"
echo ""

# 6. Verificar eventos recientes en la base de datos (si hay tabla de eventos)
echo "📊 [DEBUG] Últimas salas activas:"
php artisan tinker --execute="
\$rooms = \App\Models\Room::where('status', 'active')->orWhere('status', 'playing')->latest()->take(5)->get(['code', 'status', 'updated_at']);
foreach (\$rooms as \$room) {
    echo \$room->code . ' - ' . \$room->status . ' - ' . \$room->updated_at . PHP_EOL;
}
"
echo ""

echo "✅ [DEBUG] Debugging completado"
echo ""
echo "💡 Para monitorear en tiempo real:"
echo "   tail -f storage/logs/laravel.log | grep -E '(GameCountdownEvent|game.countdown)'"

