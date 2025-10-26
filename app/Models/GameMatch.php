<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameMatch extends Model
{
    /**
     * Nombre de la tabla (como 'Match' es reservada, usamos 'matches').
     *
     * @var string
     */
    protected $table = 'matches';

    /**
     * Los atributos que se pueden asignar masivamente.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'room_id',
        'started_at',
        'finished_at',
        'winner_id',
        'game_state',
    ];

    /**
     * Los atributos que deben ser casteados.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'game_state' => 'array', // JSON a array asociativo
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Lock key adquirido para prevenir race conditions.
     * Se guarda al adquirir el lock y se usa al liberarlo.
     *
     * @var string|null
     */
    protected ?string $acquiredLockKey = null;

    /**
     * Relación: La partida pertenece a una sala.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Relación: La partida tiene muchos jugadores.
     */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class, 'match_id');
    }

    /**
     * Relación: La partida tiene muchos eventos (opcional).
     */
    public function events(): HasMany
    {
        return $this->hasMany(MatchEvent::class, 'match_id');
    }

    /**
     * Relación: El ganador es un jugador.
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'winner_id');
    }

    /**
     * Scope: Solo partidas en curso (started pero no finished).
     */
    public function scopeInProgress($query)
    {
        return $query->whereNotNull('started_at')
                     ->whereNull('finished_at');
    }

    /**
     * Scope: Solo partidas finalizadas.
     */
    public function scopeFinished($query)
    {
        return $query->whereNotNull('finished_at');
    }

    /**
     * Verificar si la partida está en curso.
     */
    public function isInProgress(): bool
    {
        return $this->started_at !== null && $this->finished_at === null;
    }

    /**
     * Verificar si la partida ha finalizado.
     */
    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    /**
     * Iniciar la partida (fase de transición Lobby → Game Room).
     *
     * IMPORTANTE: Este método NO carga el engine del juego.
     * Solo marca el inicio y redirige a todos al game room.
     * El engine se inicializará después, cuando todos estén conectados en el room.
     */
    public function start(): void
    {
        // 1. Marcar partida como iniciada
        $this->update([
            'started_at' => now(),
        ]);

        // 2. Actualizar estado de la sala a 'active' (no 'playing' todavía)
        $this->room->update(['status' => Room::STATUS_ACTIVE]);

        // 3. Refrescar para obtener el estado actualizado
        $this->refresh();

        \Log::info("Match started - Players will be redirected to game room", [
            'match_id' => $this->id,
            'room_code' => $this->room->code,
            'game' => $this->room->game->slug,
            'players_count' => $this->players()->count(),
        ]);

        // 4. Emitir evento game.started para redirigir a todos al room
        event(new \App\Events\GameStartedEvent($this->room));
    }

    /**
     * Inicializar el engine del juego (se llama desde el game room cuando todos están conectados).
     *
     * IMPORTANTE: Este método SÍ carga el engine.
     * Solo se debe llamar después de verificar que todos los jugadores están conectados en el room.
     */
    public function initializeEngine(): void
    {
        $game = $this->room->game;
        $engineClass = $game->getEngineClass();

        if (!$engineClass || !class_exists($engineClass)) {
            throw new \RuntimeException("Game engine not found for game: {$game->slug}");
        }

        $engine = app($engineClass);

        // 1. Inicializar configuración (se llama UNA VEZ)
        $engine->initialize($this);

        // 2. Iniciar el juego (resetea módulos y empieza desde 0)
        $engine->startGame($this);

        // 3. Actualizar estado de la sala a 'playing'
        $this->room->update(['status' => Room::STATUS_PLAYING]);

        // 4. Refrescar para obtener el estado actualizado
        $this->refresh();

        \Log::info("Game engine initialized and started", [
            'match_id' => $this->id,
            'game' => $game->slug,
            'engine' => $engineClass,
            'state' => $this->game_state,
        ]);

        // 5. Emitir evento de inicialización completa
        event(new \App\Events\Game\GameInitializedEvent($this, $this->game_state));
    }

    /**
     * Finalizar la partida.
     */
    public function finish(?Player $winner = null): void
    {
        $this->update([
            'finished_at' => now(),
            'winner_id' => $winner?->id,
        ]);

        // Actualizar estado de la sala
        $this->room->finishMatch();
    }

    /**
     * Actualizar el estado del juego.
     */
    public function updateGameState(array $state): void
    {
        $this->update(['game_state' => $state]);
    }

    /**
     * Obtener la duración de la partida en segundos.
     */
    public function duration(): ?int
    {
        if (!$this->started_at) {
            return null;
        }

        $endTime = $this->finished_at ?? now();
        return $this->started_at->diffInSeconds($endTime);
    }

    /**
     * Accessor para duración (para usar como atributo).
     */
    public function getDurationAttribute(): ?int
    {
        return $this->duration();
    }

    /**
     * Obtener jugadores conectados.
     */
    public function connectedPlayers()
    {
        return $this->players()->where('is_connected', true);
    }

    /**
     * Obtener jugadores desconectados.
     */
    public function disconnectedPlayers()
    {
        return $this->players()->where('is_connected', false);
    }

    // ========================================================================
    // LOCK MECHANISM - Race Condition Prevention
    // ========================================================================

    /**
     * Intentar adquirir lock para avanzar la ronda.
     *
     * Usa Cache::add() que es atómico - solo el primer cliente lo adquiere.
     * Esto previene que múltiples jugadores avancen la ronda simultáneamente.
     *
     * @param int $ttl Tiempo de vida del lock en segundos (default: 10)
     * @return bool true si se adquirió el lock, false si otro cliente lo tiene
     */
    public function acquireRoundLock(int $ttl = 10): bool
    {
        $lockKey = $this->getRoundLockKey();

        // Cache::add() solo añade si la clave NO existe (operación atómica)
        // Retorna true si se añadió (lock adquirido)
        // Retorna false si ya existe (otro cliente tiene el lock)
        $acquired = \Cache::add($lockKey, true, $ttl);

        if ($acquired) {
            // 🔥 FIX RACE CONDITION: Guardar el lock key para usarlo al liberar
            // Esto previene que releaseRoundLock() calcule un key diferente
            // si la ronda avanza entre adquirir y liberar
            $this->acquiredLockKey = $lockKey;

            \Log::info('🔒 [Lock] Round lock acquired', [
                'match_id' => $this->id,
                'lock_key' => $lockKey,
                'ttl' => $ttl,
            ]);
        } else {
            \Log::warning('⏸️  [Lock] Round lock already held by another client', [
                'match_id' => $this->id,
                'lock_key' => $lockKey,
            ]);
        }

        return $acquired;
    }

    /**
     * Liberar el lock de la ronda.
     *
     * Debe llamarse en bloque finally para garantizar liberación.
     */
    public function releaseRoundLock(): void
    {
        // 🔥 FIX RACE CONDITION: Usar el lock key guardado, NO recalcular
        // Si recalculamos con getRoundLockKey(), podríamos obtener un key diferente
        // porque la ronda pudo haber avanzado entre adquirir y liberar
        $lockKey = $this->acquiredLockKey ?? $this->getRoundLockKey();

        \Cache::forget($lockKey);

        \Log::info('🔓 [Lock] Round lock released', [
            'match_id' => $this->id,
            'lock_key' => $lockKey,
            'was_saved' => $this->acquiredLockKey !== null,
        ]);

        // Limpiar el lock key guardado
        $this->acquiredLockKey = null;
    }

    /**
     * Verificar si el lock de la ronda está activo.
     *
     * @return bool true si el lock está activo
     */
    public function hasRoundLock(): bool
    {
        return \Cache::has($this->getRoundLockKey());
    }

    /**
     * Obtener la clave del lock para la ronda actual.
     *
     * La clave incluye el match_id y el round actual para que cada
     * ronda tenga su propio lock independiente.
     *
     * @return string
     */
    protected function getRoundLockKey(): string
    {
        $phase = $this->game_state['phase'] ?? 'unknown';

        // 🔥 FIX RACE CONDITION: NO incluir current_round en el lock key
        // Si incluimos current_round, dos requests pueden adquirir locks diferentes:
        // - Request A: lock para round:1
        // - Request A avanza a round:2 y guarda en BD
        // - Request B lee round:2, intenta lock para round:2 → ¡disponible!
        //
        // Solución: Lock global por match+phase, sin depender de la ronda
        return sprintf(
            'match:%d:phase:%s:lock',
            $this->id,
            $phase
        );
    }

    /**
     * Obtener el engine del juego.
     *
     * @return mixed
     */
    public function getEngine()
    {
        return $this->room->game->getEngine();
    }

    /**
     * Procesar una acción del juego.
     *
     * Método wrapper para evitar tener que obtener el engine cada vez.
     *
     * @param Player $player
     * @param string $action
     * @param array $data
     * @return array
     */
    public function processAction(Player $player, string $action, array $data = []): array
    {
        $engine = $this->getEngine();
        
        return $engine->processAction(
            match: $this,
            player: $player,
            action: $action,
            data: $data
        );
    }
}
