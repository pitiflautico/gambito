<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use App\Services\Modules\RoundSystem\RoundManager;
use App\Services\Modules\TurnSystem\TurnManager;
use Games\Trivia\TriviaEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test del Sistema de Timer en Trivia
 *
 * Verifica que:
 * - El TimerService se conecta correctamente al TurnManager
 * - El timer se inicia automáticamente cuando comienza el juego
 * - El timer tiene el timeLimit correcto
 * - El endpoint turn-timeout funciona correctamente
 */
class TriviaTimerSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $master;
    protected Room $room;
    protected GameMatch $match;
    protected array $players;
    protected TriviaEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('config:clear');
        $this->artisan('cache:clear');

        // Crear usuario master
        $this->master = User::create([
            'name' => 'Master',
            'email' => 'master@test.com',
            'password' => bcrypt('password')
        ]);

        // Crear juego Trivia
        $game = Game::create([
            'name' => 'Trivia',
            'slug' => 'trivia',
            'path' => 'games/trivia',
            'min_players' => 1,
            'max_players' => 10,
            'description' => 'Timer test game',
            'engine_class' => 'Games\\Trivia\\TriviaEngine'
        ]);

        // Crear sala
        $this->room = Room::create([
            'code' => 'TIMER1',
            'game_id' => $game->id,
            'master_id' => $this->master->id,
            'status' => 'playing',
            'settings' => [
                'time_per_question' => 15,
                'questions_per_game' => 3,
                'category' => 'mixed',
                'difficulty' => 'mixed'
            ]
        ]);

        // Crear match
        $this->match = GameMatch::create([
            'room_id' => $this->room->id,
            'status' => 'in_progress',
            'game_state' => []
        ]);

        // Crear 3 jugadores
        $this->players = [];
        foreach (['Alice', 'Bob', 'Carol'] as $name) {
            $player = Player::create([
                'name' => $name,
                'user_id' => null,
                'match_id' => $this->match->id,
                'is_connected' => true
            ]);

            $this->players[] = $player;
        }

        // Inicializar engine
        $this->engine = new TriviaEngine();
    }

    /**
     * Test: Verificar que el TimerService se conecta al TurnManager en initialize()
     */
    public function test_timer_service_connects_to_turn_manager_on_initialize(): void
    {
        // Inicializar juego
        $this->engine->initialize($this->match, $this->room->settings);

        // Recargar match para ver el estado actualizado
        $this->match->refresh();
        $gameState = $this->match->game_state;

        // Verificar que turn_system existe y tiene time_limit
        $this->assertArrayHasKey('turn_system', $gameState);
        $this->assertEquals(15, $gameState['turn_system']['time_limit']);

        // Verificar que timer_system existe
        $this->assertArrayHasKey('timer_system', $gameState);
        $this->assertArrayHasKey('timers', $gameState['timer_system']);

        // En este punto el timer aún NO debería estar iniciado
        // porque initialize() solo prepara los módulos, no los resetea
        $this->assertEmpty($gameState['timer_system']['timers']);
    }

    /**
     * Test: Verificar que el timer se inicia automáticamente en startGame()
     */
    public function test_timer_starts_automatically_on_game_start(): void
    {
        // Inicializar y empezar juego
        $this->engine->initialize($this->match, $this->room->settings);
        $this->engine->startGame($this->match);

        // Recargar match
        $this->match->refresh();
        $gameState = $this->match->game_state;

        // Verificar que el timer se creó
        $this->assertArrayHasKey('timer_system', $gameState);
        $this->assertArrayHasKey('timers', $gameState['timer_system']);

        // Debe existir un timer llamado 'turn_timer'
        $this->assertNotEmpty($gameState['timer_system']['timers']);

        $timers = $gameState['timer_system']['timers'];
        $this->assertArrayHasKey('turn_timer', $timers);

        // Verificar propiedades del timer
        $turnTimer = $timers['turn_timer'];
        $this->assertEquals(15, $turnTimer['duration']);
        $this->assertArrayHasKey('started_at', $turnTimer);
        // El started_at puede ser string (fecha) o int (timestamp)
        $this->assertNotEmpty($turnTimer['started_at']);
    }

    /**
     * Test: Verificar que getRemainingTime() funciona correctamente
     */
    public function test_get_remaining_time_works_correctly(): void
    {
        // Inicializar y empezar juego
        $this->engine->initialize($this->match, $this->room->settings);
        $this->engine->startGame($this->match);

        // Recargar match
        $this->match->refresh();
        $gameState = $this->match->game_state;

        // Recrear TurnManager desde el estado
        $roundManager = RoundManager::fromArray($gameState);
        $turnManager = $roundManager->getTurnManager();

        // Verificar que getRemainingTime() devuelve un valor válido
        $remaining = $turnManager->getRemainingTime();
        $this->assertNotNull($remaining);
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(15, $remaining);
    }

    /**
     * Test: Verificar que isTimeExpired() funciona correctamente
     */
    public function test_is_time_expired_works_correctly(): void
    {
        // Inicializar y empezar juego
        $this->engine->initialize($this->match, $this->room->settings);
        $this->engine->startGame($this->match);

        // Recargar match
        $this->match->refresh();
        $gameState = $this->match->game_state;

        // Recrear TurnManager desde el estado
        $roundManager = RoundManager::fromArray($gameState);
        $turnManager = $roundManager->getTurnManager();

        // Inmediatamente después de iniciar, NO debería estar expirado
        $this->assertFalse($turnManager->isTimeExpired());

        // Simular que pasó el tiempo modificando el timer
        $gameState['timer_system']['timers']['turn_timer']['started_at'] = time() - 20; // 20 segundos atrás
        $this->match->game_state = $gameState;
        $this->match->save();

        // Recrear managers con el tiempo modificado
        $this->match->refresh();
        $roundManager = RoundManager::fromArray($this->match->game_state);
        $turnManager = $roundManager->getTurnManager();

        // Ahora SÍ debería estar expirado
        $this->assertTrue($turnManager->isTimeExpired());
    }

    /**
     * Test: Verificar que el endpoint /turn-timeout responde correctamente
     */
    public function test_turn_timeout_endpoint_works_correctly(): void
    {
        // Inicializar y empezar juego
        $this->engine->initialize($this->match, $this->room->settings);
        $this->engine->startGame($this->match);

        // Recargar match
        $this->match->refresh();

        // Intentar llamar a turn-timeout ANTES de que expire (debería fallar)
        $response = $this->postJson("/api/games/{$this->match->id}/turn-timeout");

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error' => 'Turn time has not expired yet'
        ]);

        // Simular que pasó el tiempo
        $gameState = $this->match->game_state;
        $gameState['timer_system']['timers']['turn_timer']['started_at'] = time() - 20; // 20 segundos atrás
        $this->match->game_state = $gameState;
        $this->match->save();

        // Ahora llamar a turn-timeout DESPUÉS de que expire (debería funcionar)
        $response = $this->postJson("/api/games/{$this->match->id}/turn-timeout");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true
        ]);
    }

    /**
     * Test: Verificar que el timer se reinicia en cada ronda
     */
    public function test_timer_restarts_on_each_round(): void
    {
        // Inicializar y empezar juego
        $this->engine->initialize($this->match, $this->room->settings);
        $this->engine->startGame($this->match);

        // Recargar y obtener el started_at del primer turno
        $this->match->refresh();
        $firstStartedAt = $this->match->game_state['timer_system']['timers']['turn_timer']['started_at'];

        // Simular respuesta de todos los jugadores para avanzar ronda
        sleep(1); // Esperar 1 segundo para que cambie el timestamp

        foreach ($this->players as $player) {
            $this->postJson("/api/trivia/{$this->match->room->code}/answer", [
                'player_id' => $player->id,
                'answer' => 0
            ]);
        }

        // Recargar y verificar que el timer se reinició
        $this->match->refresh();
        $secondStartedAt = $this->match->game_state['timer_system']['timers']['turn_timer']['started_at'];

        // El started_at debería ser diferente (más reciente)
        $this->assertGreaterThan($firstStartedAt, $secondStartedAt);
    }
}
