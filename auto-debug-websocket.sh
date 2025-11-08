#!/bin/bash

# Script automatizado de debugging completo
# Ejecutar en el servidor: bash auto-debug-websocket.sh

echo "🔍 [AUTO-DEBUG] Iniciando debugging automatizado..."
echo ""

# 1. Verificar configuración
echo "📋 [1/5] Verificando configuración..."
php artisan tinker --execute="
echo 'Queue Connection: ' . config('queue.default') . PHP_EOL;
echo 'Broadcast Connection: ' . config('broadcasting.default') . PHP_EOL;
echo 'Reverb Host: ' . config('broadcasting.connections.reverb.options.host') . PHP_EOL;
echo 'Reverb Port: ' . config('broadcasting.connections.reverb.options.port') . PHP_EOL;
"

# 2. Verificar que Reverb esté corriendo
echo ""
echo "📡 [2/5] Verificando Reverb..."
REVERB_PID=$(ps aux | grep -i reverb | grep -v grep | awk '{print $2}')
if [ -z "$REVERB_PID" ]; then
    echo "❌ Reverb NO está corriendo"
    echo "💡 Ejecuta: php artisan reverb:start --host=0.0.0.0 --port=443 --debug"
else
    echo "✅ Reverb está corriendo (PID: $REVERB_PID)"
fi

# 3. Verificar jobs en cola
echo ""
echo "📊 [3/5] Verificando jobs en cola..."
JOBS_COUNT=$(php artisan tinker --execute="echo \DB::table('jobs')->count();" 2>/dev/null | tail -1)
if [ "$JOBS_COUNT" -gt 0 ]; then
    echo "⚠️  Hay $JOBS_COUNT jobs en cola"
    echo "💡 Si QUEUE_CONNECTION no es 'sync', los eventos pueden tardar"
else
    echo "✅ No hay jobs en cola"
fi

# 4. Buscar sala activa reciente
echo ""
echo "🎮 [4/5] Buscando salas activas recientes..."
php artisan tinker --execute="
\$rooms = \App\Models\Room::where('status', 'active')
    ->orWhere('status', 'playing')
    ->latest()
    ->take(3)
    ->get(['code', 'status', 'updated_at']);
    
if (\$rooms->count() > 0) {
    foreach (\$rooms as \$room) {
        echo \$room->code . ' - ' . \$room->status . ' - ' . \$room->updated_at . PHP_EOL;
    }
} else {
    echo 'No hay salas activas' . PHP_EOL;
}
"

# 5. Verificar logs recientes
echo ""
echo "📋 [5/5] Últimos logs de GameCountdownEvent (últimas 10 líneas):"
tail -n 50 storage/logs/laravel.log | grep -E "(GameCountdownEvent|game.countdown|apiReady)" | tail -10 || echo "No se encontraron logs recientes"

echo ""
echo "✅ Debugging completado"
echo ""
echo "💡 Para monitorear en tiempo real:"
echo "   tail -f storage/logs/laravel.log | grep -E '(GameCountdownEvent|game.countdown)'"

