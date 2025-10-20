<?php

namespace App\Console\Commands;

use App\Services\Core\GameRegistry;
use Illuminate\Console\Command;

class DiscoverGamesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'games:discover
                            {--register : Automatically register discovered games in the database}
                            {--force : Force re-registration of existing games}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Discover and optionally register game modules in the games/ directory';

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
        $this->info('🔍 Discovering game modules...');
        $this->newLine();

        $games = $this->registry->discoverGames();

        if (empty($games)) {
            $this->warn('⚠️  No valid game modules found in ' . config('games.path'));
            return Command::SUCCESS;
        }

        $this->info("✅ Found {$this->pluralize(count($games), 'valid game module')}:");
        $this->newLine();

        // Mostrar tabla con juegos descubiertos
        $tableData = [];
        foreach ($games as $game) {
            $config = $game['config'];
            $capabilities = $game['capabilities'];

            $requiredServices = [];
            if (isset($capabilities['requires'])) {
                foreach ($capabilities['requires'] as $service => $required) {
                    if ($required) {
                        $requiredServices[] = $service;
                    }
                }
            }

            $tableData[] = [
                $game['slug'],
                $config['name'] ?? 'N/A',
                $config['version'] ?? 'N/A',
                $config['minPlayers'] . '-' . $config['maxPlayers'],
                implode(', ', $requiredServices) ?: 'None',
            ];
        }

        $this->table(
            ['Slug', 'Name', 'Version', 'Players', 'Required Services'],
            $tableData
        );

        // Si se especifica --register, registrar los juegos
        if ($this->option('register')) {
            $this->newLine();
            $this->info('📝 Registering games in database...');

            $stats = $this->registry->registerAllGames();

            $this->newLine();
            $this->info("✅ Successfully registered: {$stats['registered']} {$this->pluralize($stats['registered'], 'game')}");

            if ($stats['failed'] > 0) {
                $this->warn("⚠️  Failed to register: {$stats['failed']} {$this->pluralize($stats['failed'], 'game')}");
            }

            $this->newLine();
            $this->info('💡 Tip: Use games:validate to check for issues in game modules');
        } else {
            $this->newLine();
            $this->info('💡 Tip: Use --register to automatically register these games in the database');
        }

        return Command::SUCCESS;
    }

    /**
     * Pluralize a word based on count.
     *
     * @param int $count
     * @param string $singular
     * @param string|null $plural
     * @return string
     */
    protected function pluralize(int $count, string $singular, ?string $plural = null): string
    {
        if ($count === 1) {
            return "{$count} {$singular}";
        }

        return "{$count} " . ($plural ?? $singular . 's');
    }
}
