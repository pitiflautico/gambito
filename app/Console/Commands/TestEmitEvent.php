<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Room;
use App\Events\Game\GameStartedEvent;
use App\Events\Game\PlayerLockedEvent;
use App\Events\Game\PlayersUnlockedEvent;
use App\Events\Mockup\Phase1StartedEvent;
use App\Events\Mockup\Phase1EndedEvent;

class TestEmitEvent extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:emit-event {event} {room_code}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Emit test events manually for debugging WebSocket communication';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $eventName = $this->argument('event');
        $roomCode = $this->argument('room_code');

        // Buscar room
        $room = Room::where('code', $roomCode)->with('match')->first();

        if (!$room) {
            $this->error("❌ Room not found: {$roomCode}");
            return 1;
        }

        if (!$room->match) {
            $this->error("❌ No active match for room: {$roomCode}");
            return 1;
        }

        $match = $room->match;

        // Map de eventos disponibles
        $events = [
            'GameStarted' => function() use ($match) {
                $this->info("🎮 Emitting GameStartedEvent for room {$match->room->code}");
                event(new GameStartedEvent($match, $match->game_state ?? []));
            },
            'Phase1Started' => function() use ($match) {
                $this->info("🎯 Emitting Phase1StartedEvent for room {$match->room->code}");
                $phaseData = ['name' => 'phase1', 'duration' => 5];
                event(new Phase1StartedEvent($match, $phaseData));
            },
            'Phase1Ended' => function() use ($match) {
                $this->info("🏁 Emitting Phase1EndedEvent for room {$match->room->code}");
                $phaseData = ['name' => 'phase1'];
                event(new Phase1EndedEvent($match, $phaseData));
            },
            'PlayerLocked' => function() use ($match) {
                $player = $match->players->first();
                if (!$player) {
                    $this->error("❌ No players found in match");
                    return;
                }
                $this->info("🔒 Emitting PlayerLockedEvent for room {$match->room->code} and player {$player->name}");
                event(new PlayerLockedEvent($match, $player, []));
            },
            'PlayersUnlocked' => function() use ($match) {
                $this->info("🔓 Emitting PlayersUnlockedEvent for room {$match->room->code}");
                event(new PlayersUnlockedEvent($match, []));
            },
        ];

        if (!isset($events[$eventName])) {
            $this->error("❌ Unknown event: {$eventName}");
            $this->info("Available events: " . implode(', ', array_keys($events)));
            return 1;
        }

        // Emitir evento
        $events[$eventName]();

        $this->info("✅ Event emitted successfully!");
        $this->info("Check browser console for: {$eventName}");

        return 0;
    }
}
