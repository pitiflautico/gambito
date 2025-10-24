<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Room;
use App\Models\GameMatch;
use App\Events\Game\RoundStartedEvent;

$room = Room::where('code', 'MNEFC4')->first();
$match = GameMatch::where('room_id', $room->id)->whereNull('finished_at')->first();

if (!$match) {
    echo "❌ No hay match activo\n";
    exit(1);
}

echo "✅ Disparando evento RoundStartedEvent...\n";
echo "📡 Canal: room.{$room->code}\n";
echo "🎯 Evento: .game.round.started\n\n";

$event = new RoundStartedEvent(
    match: $match,
    currentRound: 1,
    totalRounds: 10,
    phase: 'question'
);

event($event);

echo "✅ Evento disparado. Verifica la consola del navegador.\n";
