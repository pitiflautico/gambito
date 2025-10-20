<?php

namespace App\Console\Commands;

use App\Services\Core\GameRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ValidateGameCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'games:validate
                            {slug? : The slug of a specific game to validate}
                            {--all : Validate all games in the games/ directory}
                            {--verbose : Show detailed validation information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate game module structure, configuration, and implementation';

    /**
     * Game registry service.
     */
    protected GameRegistry $registry;

    /**
     * Create a new command instance.
     */
    public function __construct(GameRegistry $registry)
    {
        parent::__construct();
        $this->registry = $registry;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $slug = $this->argument('slug');
        $all = $this->option('all');

        if (!$slug && !$all) {
            $this->error('❌ Please specify a game slug or use --all to validate all games');
            $this->info('Usage: php artisan games:validate {slug}');
            $this->info('   or: php artisan games:validate --all');
            return Command::FAILURE;
        }

        if ($all) {
            return $this->validateAllGames();
        }

        return $this->validateSingleGame($slug);
    }

    /**
     * Validate all games in the games/ directory.
     */
    protected function validateAllGames(): int
    {
        $this->info('🔍 Validating all game modules...');
        $this->newLine();

        $gamesPath = config('games.path');

        if (!File::isDirectory($gamesPath)) {
            $this->error("❌ Games directory does not exist: {$gamesPath}");
            return Command::FAILURE;
        }

        $directories = File::directories($gamesPath);

        if (empty($directories)) {
            $this->warn('⚠️  No game modules found in ' . $gamesPath);
            return Command::SUCCESS;
        }

        $totalGames = count($directories);
        $validGames = 0;
        $invalidGames = 0;

        foreach ($directories as $directory) {
            $slug = basename($directory);
            $validation = $this->registry->validateGameModule($slug);

            if ($validation['valid']) {
                $validGames++;
                $this->info("✅ {$slug}: Valid");
            } else {
                $invalidGames++;
                $this->error("❌ {$slug}: Invalid");

                if ($this->option('verbose')) {
                    foreach ($validation['errors'] as $error) {
                        $this->line("   • {$error}");
                    }
                }
            }
        }

        $this->newLine();
        $this->info("📊 Validation Summary:");
        $this->table(
            ['Status', 'Count'],
            [
                ['Total Games', $totalGames],
                ['Valid', $validGames],
                ['Invalid', $invalidGames],
            ]
        );

        return $invalidGames > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Validate a single game module.
     */
    protected function validateSingleGame(string $slug): int
    {
        $this->info("🔍 Validating game module: {$slug}");
        $this->newLine();

        $validation = $this->registry->validateGameModule($slug);

        if ($validation['valid']) {
            $this->info("✅ Game module '{$slug}' is valid!");
            $this->newLine();

            // Mostrar información detallada
            $this->showGameDetails($slug);

            return Command::SUCCESS;
        }

        $this->error("❌ Game module '{$slug}' has validation errors:");
        $this->newLine();

        foreach ($validation['errors'] as $error) {
            $this->line("  • {$error}");
        }

        $this->newLine();
        $this->info('💡 Tip: Use --verbose for more detailed information');

        return Command::FAILURE;
    }

    /**
     * Show detailed information about a game.
     */
    protected function showGameDetails(string $slug): void
    {
        $config = $this->registry->loadGameConfig($slug);
        $capabilities = $this->registry->loadGameCapabilities($slug);

        if (!empty($config)) {
            $this->info('📋 Game Configuration:');
            $configTable = [
                ['Name', $config['name'] ?? 'N/A'],
                ['Description', $config['description'] ?? 'N/A'],
                ['Version', $config['version'] ?? 'N/A'],
                ['Players', ($config['minPlayers'] ?? 'N/A') . '-' . ($config['maxPlayers'] ?? 'N/A')],
                ['Duration', $config['estimatedDuration'] ?? 'N/A'],
                ['Premium', isset($config['isPremium']) && $config['isPremium'] ? 'Yes' : 'No'],
            ];

            if (isset($config['author'])) {
                $configTable[] = ['Author', $config['author']];
            }

            $this->table(['Property', 'Value'], $configTable);
            $this->newLine();
        }

        if (!empty($capabilities)) {
            $this->info('🔧 Required Capabilities:');

            $requiredServices = [];
            if (isset($capabilities['requires'])) {
                foreach ($capabilities['requires'] as $service => $required) {
                    if ($required) {
                        $requiredServices[] = [$service, '✓'];
                    }
                }
            }

            if (empty($requiredServices)) {
                $this->line('  None (game is self-contained)');
            } else {
                $this->table(['Service', 'Required'], $requiredServices);
            }

            $this->newLine();

            if (isset($capabilities['provides'])) {
                $this->info('📦 Provided Resources:');

                if (isset($capabilities['provides']['events'])) {
                    $this->line('  Events: ' . implode(', ', $capabilities['provides']['events']));
                }

                if (isset($capabilities['provides']['routes'])) {
                    $this->line('  Routes: ' . implode(', ', $capabilities['provides']['routes']));
                }

                if (isset($capabilities['provides']['views'])) {
                    $this->line('  Views: ' . implode(', ', $capabilities['provides']['views']));
                }

                $this->newLine();
            }
        }
    }
}
