#!/bin/bash
# ============================================================================
# COMANDOS PARA EJECUTAR EN EL TERMINAL DE DIGITALOCEAN
# Copia y pega estos comandos uno por uno en el terminal
# ============================================================================

echo "🔍 [DEBUG] Diagnóstico completo de WebSocket en producción"
echo ""

# 1. Ir al directorio del proyecto
echo "📁 [1/7] Navegando al proyecto..."
cd /var/www/gambito || cd ~/gambito || cd /home/forge/gambito || pwd
PROJECT_DIR=$(pwd)
echo "   Directorio: $PROJECT_DIR"
echo ""

# 2. Verificar QUEUE_CONNECTION
echo "📋 [2/7] Verificando QUEUE_CONNECTION..."
QUEUE_CONN=$(php artisan tinker --execute="echo config('queue.default');" 2>/dev/null | tail -1 | tr -d '\n\r ')
echo "   Valor actual: '$QUEUE_CONN'"
if [ "$QUEUE_CONN" != "sync" ]; then
    echo "   ❌ PROBLEMA: Debe ser 'sync' para eventos inmediatos"
    echo ""
    echo "   💡 CORRECCIÓN AUTOMÁTICA:"
    if [ -f .env ]; then
        # Hacer backup
        cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
        # Cambiar QUEUE_CONNECTION
        sed -i "s/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/" .env
        php artisan config:clear
        echo "   ✅ QUEUE_CONNECTION cambiado a 'sync'"
        echo "   ✅ Cache de configuración limpiado"
    else
        echo "   ❌ No se encontró archivo .env"
    fi
else
    echo "   ✅ QUEUE_CONNECTION está correcto (sync)"
fi
echo ""

# 3. Verificar Reverb
echo "📡 [3/7] Verificando Reverb..."
REVERB_PID=$(ps aux | grep -i "reverb:start\|php.*reverb" | grep -v grep | awk '{print $2}' | head -1)
if [ -z "$REVERB_PID" ]; then
    echo "   ❌ Reverb NO está corriendo"
    echo "   💡 Para iniciarlo: php artisan reverb:start --host=0.0.0.0 --port=443 --debug"
else
    echo "   ✅ Reverb está corriendo (PID: $REVERB_PID)"
    ps aux | grep -i "reverb" | grep -v grep | head -1
fi
echo ""

# 4. Verificar configuración de broadcasting
echo "⚙️ [4/7] Configuración de broadcasting:"
php artisan tinker --execute="
echo '   Broadcast: ' . config('broadcasting.default') . PHP_EOL;
echo '   Reverb Host: ' . config('broadcasting.connections.reverb.options.host') . PHP_EOL;
echo '   Reverb Port: ' . config('broadcasting.connections.reverb.options.port') . PHP_EOL;
echo '   Reverb Scheme: ' . config('broadcasting.connections.reverb.options.scheme') . PHP_EOL;
"
echo ""

# 5. Verificar jobs en cola
echo "📊 [5/7] Jobs en cola:"
JOBS_COUNT=$(php artisan tinker --execute="echo \DB::table('jobs')->count();" 2>/dev/null | tail -1 | tr -d '\n\r ')
if [ "$JOBS_COUNT" -gt 0 ]; then
    echo "   ⚠️  Hay $JOBS_COUNT jobs en cola"
    echo "   💡 Si QUEUE_CONNECTION no es 'sync', estos jobs pueden estar bloqueando eventos"
else
    echo "   ✅ No hay jobs en cola"
fi
echo ""

# 6. Ver logs recientes de GameCountdownEvent
echo "📋 [6/7] Últimos logs de GameCountdownEvent (últimas 5 líneas):"
tail -n 100 storage/logs/laravel.log 2>/dev/null | grep -E "(GameCountdownEvent|game.countdown|apiReady)" | tail -5 || echo "   No se encontraron logs recientes"
echo ""

# 7. Buscar salas activas
echo "🎮 [7/7] Salas activas recientes:"
php artisan tinker --execute="
\$rooms = \App\Models\Room::where('status', 'active')
    ->orWhere('status', 'playing')
    ->latest()
    ->take(3)
    ->get(['code', 'status', 'updated_at']);
    
if (\$rooms->count() > 0) {
    foreach (\$rooms as \$room) {
        echo '   ' . \$room->code . ' - ' . \$room->status . ' - ' . \$room->updated_at . PHP_EOL;
    }
} else {
    echo '   No hay salas activas' . PHP_EOL;
}
"

echo ""
echo "✅ Diagnóstico completado"
echo ""
echo "💡 Para monitorear en tiempo real:"
echo "   tail -f storage/logs/laravel.log | grep -E '(GameCountdownEvent|game.countdown)'"
echo ""
echo "💡 Para probar emisión manual de evento:"
echo "   php test-emit-countdown.php [ROOM_CODE]"

