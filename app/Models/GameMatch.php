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
     * Iniciar la partida.
     */
    public function start(): void
    {
        $this->update([
            'started_at' => now(),
            'game_state' => $this->game_state ?? [], // Inicializar estado si está vacío
        ]);
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
    public function getDurationAttribute(): ?int
    {
        if ($this->started_at && $this->finished_at) {
            return $this->finished_at->diffInSeconds($this->started_at);
        }

        return null;
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
}
