<?php

namespace App\Console\Commands\Debug;

use App\Models\Room;
use App\Models\GameMatch;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Console\Command;

class CreateTestRoom extends Command
{
    protected $signature = 'debug:create-test-room';
    protected $description = 'Create or reset the TEST01 room for debugging';

    public function handle()
    {
        // Buscar sala de pruebas existente
        $room = Room::where('code', 'TEST01')->first();

        if ($room) {
            $this->info('🔄 Resetting existing TEST01 room...');

            // Eliminar players
            if ($room->match) {
                $room->match->players()->delete();
                $room->match->update(['game_state' => []]);
            } else {
                // Crear match si no existe
                GameMatch::create([
                    'room_id' => $room->id,
                    'game_state' => [],
                ]);
            }

            $room->update(['status' => 'waiting']);

            $this->info('✅ TEST01 room reset successfully!');
        } else {
            $this->info('🆕 Creating new TEST01 room...');

            $triviaGame = Game::where('slug', 'trivia')->firstOrFail();
            $masterUser = \App\Models\User::first(); // Get first user as master

            $room = Room::create([
                'code' => 'TEST01',
                'game_id' => $triviaGame->id,
                'master_id' => $masterUser->id,
                'status' => 'waiting',
            ]);

            GameMatch::create([
                'room_id' => $room->id,
                'game_state' => [],
            ]);

            $this->info('✅ TEST01 room created successfully!');
        }

        // Reload relationships
        $room->load(['match', 'game']);

        $this->newLine();
        $this->line('📋 Room Details:');
        $this->line("   Code: TEST01");
        $this->line("   Room ID: {$room->id}");
        $this->line("   Match ID: " . ($room->match ? $room->match->id : 'N/A'));
        $this->line("   Game: {$room->game->name}");

        $this->newLine();
        $this->line('🔗 URLs:');
        $this->line('   Debug Panel: ' . url('/debug/game-events/TEST01'));
        $this->line('   Join Room: ' . url('/rooms/join'));
        $this->line('   Lobby: ' . url('/rooms/TEST01/lobby'));

        return 0;
    }
}
