<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Room;
use App\Models\GameMatch;

// Obtener sala por código
$room = Room::where('code', 'SWKVFC')->first();

if (!$room) {
    echo "❌ Sala SWKVFC no encontrada\n";
    exit(1);
}

echo "🎮 Sala: {$room->code} (ID: {$room->id})\n";
echo "   Estado: {$room->status}\n";
echo "   current_match_id: {$room->current_match_id}\n\n";

// Obtener todos los matches de esta sala
$matches = GameMatch::where('room_id', $room->id)
    ->orderBy('created_at', 'desc')
    ->get();

echo "📊 Matches encontrados: {$matches->count()}\n\n";

foreach ($matches as $match) {
    echo "Match ID: {$match->id}\n";
    echo "  Creado: {$match->created_at}\n";
    echo "  Iniciado: " . ($match->started_at ?? 'No') . "\n";
    echo "  Finalizado: " . ($match->finished_at ?? 'No') . "\n";
    echo "  Estado: " . ($match->isInProgress() ? '✅ En progreso' : ($match->isFinished() ? '❌ Finalizado' : '⏸️  No iniciado')) . "\n";

    if ($match->game_state) {
        $phase = $match->game_state['phase'] ?? 'unknown';
        $round = $match->game_state['round_system']['current_round'] ?? 'N/A';
        echo "  Phase: {$phase}\n";
        echo "  Round: {$round}\n";
    }

    echo "\n";
}

// Si current_match_id apunta a un match que no existe, intentar encontrar el último match en progreso
if ($room->current_match_id) {
    $currentMatch = GameMatch::find($room->current_match_id);
    if (!$currentMatch) {
        echo "⚠️  PROBLEMA: current_match_id apunta a match inexistente: {$room->current_match_id}\n\n";
    }
}

// Buscar último match en progreso
$lastInProgress = GameMatch::where('room_id', $room->id)
    ->whereNotNull('started_at')
    ->whereNull('finished_at')
    ->orderBy('created_at', 'desc')
    ->first();

if ($lastInProgress) {
    echo "🔍 Último match en progreso: {$lastInProgress->id}\n";
    echo "   Actualizar current_match_id de la sala?\n";
}
