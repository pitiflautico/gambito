<?php
/**
 * Script de prueba para emitir eventos de WebSocket
 *
 * Uso: php test-events.php [room_code] [event_type]
 *
 * Eventos disponibles:
 * - round-started
 * - round-ended
 * - player-action
 * - phase-changed
 * - turn-changed
 * - game-finished
 */

require __DIR__.'/vendor/autoload.php';

use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\Application;

// Bootstrap Laravel
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
Facade::setFacadeApplication($app);

// Argumentos
$roomCode = $argv[1] ?? 'MNEFC4';
$eventType = $argv[2] ?? 'round-started';

echo "🚀 Emitiendo evento de prueba...\n";
echo "📍 Room Code: {$roomCode}\n";
echo "📡 Event Type: {$eventType}\n";
echo "\n";

// Buscar room y match reales en la BD
$room = \App\Models\Room::where('code', $roomCode)->first();

if (!$room) {
    echo "❌ Error: No se encontró la sala con código {$roomCode}\n";
    echo "💡 Crea una sala primero en el navegador\n";
    exit(1);
}

$match = \App\Models\GameMatch::where('room_id', $room->id)
    ->latest()
    ->first();

if (!$match) {
    echo "❌ Error: No se encontró un match en la sala {$roomCode}\n";
    echo "💡 Inicia una partida primero en el navegador\n";
    exit(1);
}

// Buscar jugadores del match
$players = $match->players()->get();

if ($players->isEmpty()) {
    echo "❌ Error: No hay jugadores en el match\n";
    echo "💡 Únete a la sala primero\n";
    exit(1);
}

$player1 = $players->first();
$player2 = $players->count() > 1 ? $players->get(1) : $player1;

echo "✅ Match encontrado (ID: {$match->id})\n";
echo "👥 Jugadores: {$players->count()}\n";
echo "   - {$player1->name} (ID: {$player1->id})\n";
if ($player2->id !== $player1->id) {
    echo "   - {$player2->name} (ID: {$player2->id})\n";
}
echo "\n";

switch ($eventType) {
    case 'round-started':
        $event = new \App\Events\Game\RoundStartedEvent(
            match: $match,
            currentRound: 1,
            totalRounds: 10,
            phase: 'question',
        );

        echo "📤 Broadcasting to channel: " . $event->broadcastOn()->name . "\n";
        echo "📤 Event name: " . $event->broadcastAs() . "\n";
        echo "📤 Event data: " . json_encode($event->broadcastWith(), JSON_PRETTY_PRINT) . "\n";

        event($event);

        echo "✅ RoundStartedEvent emitido!\n";
        echo "   - Ronda: 1/10\n";
        echo "   - Fase: question\n";
        break;

    case 'round-ended':
        event(new \App\Events\Game\RoundEndedEvent(
            match: $match,
            roundNumber: 1,
            results: [
                'correct_answer' => 'Madrid',
                'winners' => [$player1->id, $player2->id],
                'fastest_player' => $player1->id,
            ],
            scores: [
                $player1->id => 150,
                $player2->id => 125,
            ],
        ));
        echo "✅ RoundEndedEvent emitido!\n";
        echo "   - Ronda: 1\n";
        echo "   - Ganadores: [{$player1->name}, {$player2->name}]\n";
        echo "   - Scores actualizados\n";
        break;

    case 'player-action':
        event(new \App\Events\Game\PlayerActionEvent(
            match: $match,
            player: $player1,
            actionType: 'answer',
            actionData: ['answer_index' => 0, 'time_taken' => 3.5],
            success: true,
        ));
        echo "✅ PlayerActionEvent emitido!\n";
        echo "   - Jugador: {$player1->name} (ID: {$player1->id})\n";
        echo "   - Acción: answer\n";
        echo "   - Éxito: true\n";
        break;

    case 'phase-changed':
        event(new \App\Events\Game\PhaseChangedEvent(
            match: $match,
            newPhase: 'results',
            previousPhase: 'playing',
            additionalData: ['next_phase_in' => 5],
        ));
        echo "✅ PhaseChangedEvent emitido!\n";
        echo "   - Nueva fase: results\n";
        echo "   - Fase anterior: playing\n";
        break;

    case 'turn-changed':
        event(new \App\Events\Game\TurnChangedEvent(
            match: $match,
            currentPlayerId: $player2->id,
            currentPlayerName: $player2->name,
            currentRound: 2,
            turnIndex: 1,
            cycleCompleted: false,
            playerRoles: [$player1->id => 'player', $player2->id => 'player'],
        ));
        echo "✅ TurnChangedEvent emitido!\n";
        echo "   - Jugador actual: {$player2->name} (ID: {$player2->id})\n";
        echo "   - Ronda: 2\n";
        echo "   - Índice de turno: 1\n";
        break;

    case 'game-finished':
        $ranking = [
            ['player_id' => 1, 'name' => 'Daniel', 'score' => 500, 'position' => 1],
            ['player_id' => 2, 'name' => 'María', 'score' => 400, 'position' => 2],
            ['player_id' => 3, 'name' => 'Juan', 'score' => 300, 'position' => 3],
        ];

        $statistics = [
            'total_rounds' => 10,
            'total_time' => 300,
            'average_time_per_round' => 30,
            'winner' => ['id' => 1, 'name' => 'Daniel', 'score' => 500],
        ];

        event(new \Games\Trivia\Events\GameFinishedEvent(
            match: $match,
            ranking: $ranking,
            statistics: $statistics,
        ));
        echo "✅ GameFinishedEvent emitido!\n";
        echo "   - Ganador: Daniel (500 puntos)\n";
        echo "   - Total de rondas: 10\n";
        break;

    default:
        echo "❌ Evento desconocido: {$eventType}\n";
        echo "\nEventos disponibles:\n";
        echo "  - round-started\n";
        echo "  - round-ended\n";
        echo "  - player-action\n";
        echo "  - phase-changed\n";
        echo "  - turn-changed\n";
        echo "  - game-finished\n";
        exit(1);
}

echo "\n";
echo "🎯 Abre la consola del navegador en la sala {$roomCode} para ver el evento.\n";
echo "\n";
