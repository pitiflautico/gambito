<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Room;
use App\Models\GameMatch;
use App\Events\Game\GameStartedEvent;

// Obtener sala por código
$room = Room::where('code', 'SWKVFC')->first();

if (!$room) {
    echo "❌ Sala SWKVFC no encontrada\n";
    exit(1);
}

echo "🎮 Sala encontrada: {$room->code}\n";
echo "   Room ID: {$room->id}\n";
echo "   Estado: {$room->status}\n";

// Obtener match activo
$match = $room->currentMatch;

if (!$match) {
    echo "❌ No hay match activo en esta sala\n";
    exit(1);
}

echo "\n📊 Match encontrado:\n";
echo "   Match ID: {$match->id}\n";
echo "   Estado: " . ($match->isInProgress() ? 'En progreso' : 'No iniciado') . "\n";

// Obtener game engine
$game = $room->game;
$engineClass = $game->getEngineClass();

if (!$engineClass || !class_exists($engineClass)) {
    echo "❌ Engine no encontrado: {$engineClass}\n";
    exit(1);
}

$engine = app($engineClass);

echo "\n🔧 Engine: {$engineClass}\n";

// Obtener timing metadata desde config.json
$timing = $engine->getGameStartTiming($match);

echo "\n⏱️  Timing metadata:\n";
echo json_encode($timing, JSON_PRETTY_PRINT) . "\n";

// Emitir evento GameStartedEvent
echo "\n🚀 Emitiendo GameStartedEvent...\n";

event(new GameStartedEvent(
    match: $match,
    gameState: $match->game_state,
    timing: $timing
));

echo "✅ Evento emitido correctamente\n";
echo "\n📡 Los clientes conectados deberían ver:\n";
echo "   1. Countdown de {$timing['countdown_seconds']} segundos\n";
echo "   2. Primera pregunta después del countdown\n";
