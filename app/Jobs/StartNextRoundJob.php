<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job para iniciar la siguiente ronda con delay.
 *
 * Este job se usa cuando necesitamos dar tiempo a los jugadores
 * para ver los resultados antes de pasar a la siguiente ronda.
 */
class StartNextRoundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * El closure que se ejecutará para iniciar la siguiente ronda.
     *
     * @var \Closure
     */
    protected $callback;

    /**
     * Create a new job instance.
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('⏰ [StartNextRoundJob] Executing delayed callback');

        try {
            ($this->callback)();
            Log::info('✅ [StartNextRoundJob] Callback executed successfully');
        } catch (\Exception $e) {
            Log::error('❌ [StartNextRoundJob] Error executing callback', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
