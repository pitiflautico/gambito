<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        \App\Events\Game\RoundEndedEvent::class => [
            \App\Listeners\CancelPhaseManagerTimersOnRoundEnd::class,
        ],
        \App\Events\Game\PhaseTimerExpiredEvent::class => [
            \App\Listeners\HandleGenericPhaseTimerExpired::class,
        ],
        // StartNewRoundEvent registered in boot() to avoid duplicate registration
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        \Log::warning('🔧 [EventServiceProvider] Registering StartNewRoundEvent listener', [
            'backtrace' => array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 0, 5)
        ]);

        // Register StartNewRoundEvent listener manually to ensure single registration
        \Event::listen(
            \App\Events\Game\StartNewRoundEvent::class,
            [\App\Listeners\HandleStartNewRound::class, 'handleStartNewRound']
        );
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
