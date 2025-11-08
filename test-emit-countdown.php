<?php

/**
 * Script para emitir manualmente el evento game.countdown y verificar que se transmite
 * Ejecutar: php test-emit-countdown.php N3MMN6
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$roomCode = $argv[1] ?? 'N3MMN6';

echo "🔍 [TEST] Emitiendo evento game.countdown para room: {$roomCode}\n";

$room = \App\Models\Room::where('code', strtoupper($roomCode))->first();

if (!$room) {
    echo "❌ Sala no encontrada: {$roomCode}\n";
    exit(1);
}

echo "✅ Sala encontrada: {$room->code} (ID: {$room->id})\n";
echo "📊 Estado: {$room->status}\n";
echo "📊 Queue Connection: " . config('queue.default') . "\n";
echo "📊 Broadcast Connection: " . config('broadcasting.default') . "\n\n";

echo "📢 Emitiendo GameCountdownEvent...\n";

try {
    event(new \App\Events\Game\GameCountdownEvent($room, 3));
    echo "✅ Evento emitido correctamente\n";
    
    // Verificar si hay jobs en cola (si QUEUE_CONNECTION no es sync)
    if (config('queue.default') !== 'sync') {
        $jobsCount = \DB::table('jobs')->count();
        echo "📋 Jobs en cola: {$jobsCount}\n";
        
        if ($jobsCount > 0) {
            echo "⚠️  ADVERTENCIA: Hay jobs en cola. El evento puede tardar en procesarse.\n";
            echo "💡 Solución: Cambiar QUEUE_CONNECTION=sync en .env\n";
        }
    }
    
    echo "\n✅ Test completado. Verifica en el cliente si el evento llegó.\n";
    
} catch (\Exception $e) {
    echo "❌ Error al emitir evento: {$e->getMessage()}\n";
    echo "Stack trace:\n{$e->getTraceAsString()}\n";
    exit(1);
}

