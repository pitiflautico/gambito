<?php

namespace App\Console\Commands;

use App\Models\GameMatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Comando Artisan para realizar rollback manual de game_state.
 *
 * Restaura el game_state de un match al último snapshot guardado en Redis.
 * Útil para recuperación manual en caso de errores críticos.
 *
 * Uso: php artisan game:rollback {match_id}
 */
class GameRollback extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'game:rollback {match_id : ID del match a restaurar} {--force : Forzar rollback sin confirmación}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restaurar game_state de un match al último snapshot disponible en Redis';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $matchId = $this->argument('match_id');
        $force = $this->option('force');

        // 1. Verificar que el match existe
        $match = GameMatch::find($matchId);

        if (!$match) {
            $this->error("Match #{$matchId} no encontrado.");
            return Command::FAILURE;
        }

        // 2. Obtener snapshot desde Redis
        $snapshotKey = "game:snapshot:{$matchId}";
        $snapshotJson = Redis::get($snapshotKey);

        if (!$snapshotJson) {
            $this->error("No hay snapshot disponible para el match #{$matchId}.");
            $this->info("Los snapshots expiran después de 1 hora.");
            return Command::FAILURE;
        }

        try {
            $snapshotData = json_decode($snapshotJson, true);
        } catch (\Exception $e) {
            $this->error("Error al parsear snapshot: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // 3. Mostrar información del snapshot
        $this->info("=== Información del Snapshot ===");
        $this->line("Match ID: {$matchId}");
        $this->line("Timestamp: " . ($snapshotData['timestamp'] ?? 'N/A'));
        $this->line("Round: " . ($snapshotData['round'] ?? 'N/A'));
        $this->line("Game: " . ($match->room->game->name ?? 'N/A'));
        $this->line("Room: " . ($match->room->code ?? 'N/A'));
        $this->newLine();

        // 4. Confirmación (si no es --force)
        if (!$force) {
            if (!$this->confirm('¿Confirmar restauración del snapshot? Esta acción sobrescribirá el game_state actual.')) {
                $this->info('Operación cancelada.');
                return Command::CANCELLED;
            }
        }

        // 5. Realizar rollback
        try {
            // Backup del estado actual antes de rollback
            $previousState = $match->game_state;

            // Restaurar snapshot
            $match->game_state = $snapshotData['game_state'];
            $match->save();

            // Log de acción admin
            Log::warning("[GameRollback] Manual rollback executed", [
                'match_id' => $matchId,
                'snapshot_timestamp' => $snapshotData['timestamp'] ?? 'N/A',
                'snapshot_round' => $snapshotData['round'] ?? 'N/A',
                'executed_by' => 'console_command',
                'executed_at' => now()->toDateTimeString(),
                'previous_round' => $previousState['round_system']['current_round'] ?? 'N/A',
                'restored_round' => $match->game_state['round_system']['current_round'] ?? 'N/A',
            ]);

            $this->info("✓ Rollback completado exitosamente.");
            $this->line("Estado anterior (round): " . ($previousState['round_system']['current_round'] ?? 'N/A'));
            $this->line("Estado restaurado (round): " . ($match->game_state['round_system']['current_round'] ?? 'N/A'));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error al ejecutar rollback: {$e->getMessage()}");
            Log::error("[GameRollback] Failed to execute manual rollback", [
                'match_id' => $matchId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
