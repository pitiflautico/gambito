<?php

namespace Tests\Unit\Services\Core;

use App\Models\Game;
use App\Services\Core\GameRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GameRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected GameRegistry $registry;
    protected string $testGamesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new GameRegistry();
        $this->testGamesPath = base_path('tests/fixtures/games');

        // Configurar el path de juegos de prueba
        Config::set('games.path', $this->testGamesPath);

        // Crear directorio de prueba si no existe
        if (!File::isDirectory($this->testGamesPath)) {
            File::makeDirectory($this->testGamesPath, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Limpiar directorios de prueba
        if (File::isDirectory($this->testGamesPath)) {
            File::deleteDirectory($this->testGamesPath);
        }

        parent::tearDown();
    }

    /** @test */
    public function it_returns_required_config_fields()
    {
        $fields = $this->registry->getRequiredConfigFields();

        $this->assertIsArray($fields);
        $this->assertContains('id', $fields);
        $this->assertContains('name', $fields);
        $this->assertContains('slug', $fields);
        $this->assertContains('description', $fields);
        $this->assertContains('minPlayers', $fields);
        $this->assertContains('maxPlayers', $fields);
        $this->assertContains('estimatedDuration', $fields);
        $this->assertContains('version', $fields);
    }

    /** @test */
    public function it_returns_available_capabilities()
    {
        $capabilities = $this->registry->getAvailableCapabilities();

        $this->assertIsArray($capabilities);
        $this->assertContains('websockets', $capabilities);
        $this->assertContains('turns', $capabilities);
        $this->assertContains('phases', $capabilities);
        $this->assertContains('roles', $capabilities);
        $this->assertContains('timers', $capabilities);
        $this->assertContains('scoring', $capabilities);
    }

    /** @test */
    public function it_validates_config_with_all_required_fields()
    {
        $config = [
            'id' => 'test-game',
            'name' => 'Test Game',
            'slug' => 'test-game',
            'description' => 'A test game',
            'minPlayers' => 2,
            'maxPlayers' => 8,
            'estimatedDuration' => '15-20 minutes',
            'version' => '1.0.0',
        ];

        $result = $this->registry->validateConfig($config);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function it_fails_validation_when_required_fields_are_missing()
    {
        $config = [
            'name' => 'Test Game',
            // Missing other required fields
        ];

        $result = $this->registry->validateConfig($config);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Missing required config field', implode(', ', $result['errors']));
    }

    /** @test */
    public function it_validates_player_count_ranges()
    {
        $config = [
            'id' => 'test-game',
            'name' => 'Test Game',
            'slug' => 'test-game',
            'description' => 'A test game',
            'minPlayers' => 101, // Invalid: > 100
            'maxPlayers' => 8,
            'estimatedDuration' => '15-20 minutes',
            'version' => '1.0.0',
        ];

        $result = $this->registry->validateConfig($config);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('minPlayers must be between', implode(', ', $result['errors']));
    }

    /** @test */
    public function it_validates_max_players_greater_than_min_players()
    {
        $config = [
            'id' => 'test-game',
            'name' => 'Test Game',
            'slug' => 'test-game',
            'description' => 'A test game',
            'minPlayers' => 8,
            'maxPlayers' => 2, // Invalid: < minPlayers
            'estimatedDuration' => '15-20 minutes',
            'version' => '1.0.0',
        ];

        $result = $this->registry->validateConfig($config);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('maxPlayers must be greater than or equal to minPlayers', implode(', ', $result['errors']));
    }

    /** @test */
    public function it_validates_version_format()
    {
        $validVersions = ['1.0', '1.0.0', '2.5.3'];
        $invalidVersions = ['1', 'v1.0', '1.0.0.0', 'abc'];

        foreach ($validVersions as $version) {
            $config = [
                'id' => 'test-game',
                'name' => 'Test Game',
                'slug' => 'test-game',
                'description' => 'A test game',
                'minPlayers' => 2,
                'maxPlayers' => 8,
                'estimatedDuration' => '15-20 minutes',
                'version' => $version,
            ];

            $result = $this->registry->validateConfig($config);
            $this->assertTrue($result['valid'], "Version {$version} should be valid");
        }

        foreach ($invalidVersions as $version) {
            $config = [
                'id' => 'test-game',
                'name' => 'Test Game',
                'slug' => 'test-game',
                'description' => 'A test game',
                'minPlayers' => 2,
                'maxPlayers' => 8,
                'estimatedDuration' => '15-20 minutes',
                'version' => $version,
            ];

            $result = $this->registry->validateConfig($config);
            $this->assertFalse($result['valid'], "Version {$version} should be invalid");
        }
    }

    /** @test */
    public function it_validates_capabilities_with_required_structure()
    {
        $capabilities = [
            'slug' => 'test-game',
            'requires' => [
                'websockets' => true,
                'turns' => false,
                'phases' => true,
            ],
        ];

        $result = $this->registry->validateCapabilities($capabilities);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function it_fails_validation_when_capabilities_structure_is_invalid()
    {
        $capabilities = [
            // Missing 'slug' and 'requires'
            'invalid' => 'structure',
        ];

        $result = $this->registry->validateCapabilities($capabilities);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /** @test */
    public function it_fails_validation_when_invalid_capability_is_specified()
    {
        $capabilities = [
            'slug' => 'test-game',
            'requires' => [
                'websockets' => true,
                'invalid_capability' => true, // Invalid
            ],
        ];

        $result = $this->registry->validateCapabilities($capabilities);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Invalid capability', implode(', ', $result['errors']));
    }

    /** @test */
    public function it_fails_validation_when_capability_value_is_not_boolean()
    {
        $capabilities = [
            'slug' => 'test-game',
            'requires' => [
                'websockets' => 'yes', // Should be boolean
            ],
        ];

        $result = $this->registry->validateCapabilities($capabilities);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be a boolean', implode(', ', $result['errors']));
    }

    /** @test */
    public function it_returns_empty_array_when_no_games_exist()
    {
        $games = $this->registry->discoverGames();

        $this->assertIsArray($games);
        $this->assertEmpty($games);
    }

    /** @test */
    public function it_can_clear_game_cache()
    {
        Config::set('games.cache.enabled', true);

        // No debería lanzar excepción
        $this->registry->clearGameCache('test-game');
        $this->registry->clearAllCache();

        $this->assertTrue(true);
    }

    /** @test */
    public function it_returns_active_games_from_database()
    {
        // Crear juegos de prueba
        Game::create([
            'name' => 'Active Game 1',
            'slug' => 'active-game-1',
            'description' => 'Test game 1',
            'path' => 'games/active-game-1',
            'metadata' => ['version' => '1.0'],
            'is_active' => true,
        ]);

        Game::create([
            'name' => 'Inactive Game',
            'slug' => 'inactive-game',
            'description' => 'Test game 2',
            'path' => 'games/inactive-game',
            'metadata' => ['version' => '1.0'],
            'is_active' => false,
        ]);

        Game::create([
            'name' => 'Active Game 2',
            'slug' => 'active-game-2',
            'description' => 'Test game 3',
            'path' => 'games/active-game-2',
            'metadata' => ['version' => '1.0'],
            'is_active' => true,
        ]);

        $activeGames = $this->registry->getActiveGames(false);

        $this->assertCount(2, $activeGames);
        $this->assertEquals('active-game-1', $activeGames[0]->slug);
        $this->assertEquals('active-game-2', $activeGames[1]->slug);
    }
}
