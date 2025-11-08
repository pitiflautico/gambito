#!/bin/bash
# ============================================================================
# SCRIPT ÚNICO PARA EJECUTAR EN DIGITALOCEAN TERMINAL
# Copia TODO este bloque y pégalo en el terminal de DigitalOcean
# ============================================================================

set -e

echo "🔍 [AUTO-DEBUG] Diagnóstico y corrección automática de WebSocket"
echo "=================================================================="
echo ""

# Detectar directorio del proyecto
if [ -d "/var/www/gambito" ]; then
    PROJECT_DIR="/var/www/gambito"
elif [ -d "/home/forge/gambito" ]; then
    PROJECT_DIR="/home/forge/gambito"
elif [ -d "$HOME/gambito" ]; then
    PROJECT_DIR="$HOME/gambito"
else
    echo "❌ No se encontró el directorio del proyecto"
    echo "💡 Ejecuta: cd /ruta/a/tu/proyecto"
    exit 1
fi

cd "$PROJECT_DIR"
echo "📁 Directorio: $PROJECT_DIR"
echo ""

# 1. Actualizar código
echo "📥 [1/6] Actualizando código..."
git pull origin main 2>/dev/null || echo "   (git pull falló, continuando...)"
echo ""

# 2. Verificar y corregir QUEUE_CONNECTION
echo "📋 [2/6] Verificando QUEUE_CONNECTION..."
QUEUE_CONN=$(php artisan tinker --execute="echo config('queue.default');" 2>/dev/null | tail -1 | tr -d '\n\r ')

echo "   Valor actual: '$QUEUE_CONN'"

if [ "$QUEUE_CONN" != "sync" ]; then
    echo "   ❌ PROBLEMA ENCONTRADO: Debe ser 'sync' para eventos inmediatos"
    echo "   🔧 Corrigiendo automáticamente..."
    
    if [ -f .env ]; then
        # Backup
        cp .env .env.backup.$(date +%Y%m%d_%H%M%S) 2>/dev/null || true
        
        # Corregir
        if grep -q "^QUEUE_CONNECTION=" .env; then
            sed -i "s/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/" .env
        else
            echo "QUEUE_CONNECTION=sync" >> .env
        fi
        
        php artisan config:clear
        
        # Verificar
        NEW_QUEUE=$(php artisan tinker --execute="echo config('queue.default');" 2>/dev/null | tail -1 | tr -d '\n\r ')
        if [ "$NEW_QUEUE" = "sync" ]; then
            echo "   ✅ CORREGIDO: QUEUE_CONNECTION ahora es 'sync'"
        else
            echo "   ⚠️  No se pudo corregir automáticamente"
        fi
    else
        echo "   ❌ No se encontró archivo .env"
    fi
else
    echo "   ✅ QUEUE_CONNECTION está correcto (sync)"
fi
echo ""

# 3. Verificar Reverb
echo "📡 [3/6] Verificando Reverb..."
REVERB_PID=$(ps aux | grep -i "reverb:start\|php.*reverb" | grep -v grep | awk '{print $2}' | head -1)
if [ -z "$REVERB_PID" ]; then
    echo "   ❌ Reverb NO está corriendo"
    echo "   💡 Para iniciarlo:"
    echo "      php artisan reverb:start --host=0.0.0.0 --port=443 --debug"
else
    echo "   ✅ Reverb está corriendo (PID: $REVERB_PID)"
    ps aux | grep -i "reverb" | grep -v grep | head -1 | awk '{print "      Comando: " substr($0, index($0,$11))}'
fi
echo ""

# 4. Verificar configuración
echo "⚙️ [4/6] Configuración de broadcasting:"
php artisan tinker --execute="
echo '   Broadcast: ' . config('broadcasting.default') . PHP_EOL;
echo '   Reverb Host: ' . config('broadcasting.connections.reverb.options.host') . PHP_EOL;
echo '   Reverb Port: ' . config('broadcasting.connections.reverb.options.port') . PHP_EOL;
echo '   Reverb Scheme: ' . config('broadcasting.connections.reverb.options.scheme') . PHP_EOL;
" 2>/dev/null
echo ""

# 5. Verificar jobs en cola
echo "📊 [5/6] Jobs en cola:"
JOBS_COUNT=$(php artisan tinker --execute="echo \DB::table('jobs')->count();" 2>/dev/null | tail -1 | tr -d '\n\r ' 2>/dev/null || echo "0")
if [ "$JOBS_COUNT" -gt 0 ]; then
    echo "   ⚠️  Hay $JOBS_COUNT jobs en cola"
    echo "   💡 Si QUEUE_CONNECTION no es 'sync', estos pueden estar bloqueando eventos"
else
    echo "   ✅ No hay jobs en cola"
fi
echo ""

# 6. Ver logs recientes
echo "📋 [6/6] Últimos logs de GameCountdownEvent (últimas 3 líneas):"
tail -n 200 storage/logs/laravel.log 2>/dev/null | grep -E "(GameCountdownEvent|game.countdown|apiReady)" | tail -3 || echo "   No se encontraron logs recientes"

echo ""
echo "✅ Diagnóstico completado"
echo ""
echo "💡 Próximos pasos:"
echo "   1. Si QUEUE_CONNECTION fue corregido, reinicia Reverb:"
echo "      php artisan reverb:start --host=0.0.0.0 --port=443 --debug"
echo ""
echo "   2. Para monitorear en tiempo real:"
echo "      tail -f storage/logs/laravel.log | grep -E '(GameCountdownEvent|game.countdown)'"
echo ""
echo "   3. Para probar emisión manual:"
echo "      php test-emit-countdown.php [ROOM_CODE]"

