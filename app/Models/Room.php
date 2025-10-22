<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Room extends Model
{
    /**
     * Los atributos que se pueden asignar masivamente.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'game_id',
        'master_id',
        'status',
        'settings',
        'game_settings',
    ];

    /**
     * Los atributos que deben ser casteados.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'settings' => 'array', // JSON a array asociativo
        'game_settings' => 'array', // JSON a array asociativo
    ];

    /**
     * Estados posibles de una sala.
     */
    const STATUS_WAITING = 'waiting';
    const STATUS_PLAYING = 'playing';
    const STATUS_FINISHED = 'finished';

    /**
     * Boot del modelo: Generar código único automáticamente.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($room) {
            if (!$room->code) {
                $room->code = static::generateUniqueCode();
            }
        });
    }

    /**
     * Relación: La sala pertenece a un juego.
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Relación: La sala fue creada por un master (usuario).
     */
    public function master(): BelongsTo
    {
        return $this->belongsTo(User::class, 'master_id');
    }

    /**
     * Relación: La sala tiene una partida.
     */
    public function match(): HasOne
    {
        return $this->hasOne(GameMatch::class);
    }

    /**
     * Scope: Solo salas en espera.
     */
    public function scopeWaiting($query)
    {
        return $query->where('status', self::STATUS_WAITING);
    }

    /**
     * Scope: Solo salas jugando.
     */
    public function scopePlaying($query)
    {
        return $query->where('status', self::STATUS_PLAYING);
    }

    /**
     * Scope: Solo salas finalizadas.
     */
    public function scopeFinished($query)
    {
        return $query->where('status', self::STATUS_FINISHED);
    }

    /**
     * Verificar si la sala está esperando jugadores.
     */
    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_WAITING;
    }

    /**
     * Verificar si la sala está en juego.
     */
    public function isPlaying(): bool
    {
        return $this->status === self::STATUS_PLAYING;
    }

    /**
     * Verificar si la sala ha finalizado.
     */
    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    /**
     * Iniciar la partida (cambiar estado a playing).
     */
    public function startMatch(): void
    {
        $this->update(['status' => self::STATUS_PLAYING]);
    }

    /**
     * Finalizar la partida (cambiar estado a finished).
     */
    public function finishMatch(): void
    {
        $this->update(['status' => self::STATUS_FINISHED]);
    }

    /**
     * Generar un código único de 6 caracteres alfanuméricos.
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /**
     * Obtener la URL de invitación para la sala.
     */
    public function getInviteUrlAttribute(): string
    {
        return route('rooms.join', ['code' => $this->code]);
    }

    /**
     * Obtener el número de jugadores actual (si hay partida).
     */
    public function getPlayerCountAttribute(): int
    {
        return $this->match ? $this->match->players()->count() : 0;
    }

    /**
     * Verificar si el número de jugadores es válido para iniciar.
     */
    public function canStart(): bool
    {
        $playerCount = $this->player_count;
        return $this->game->isValidPlayerCount($playerCount);
    }
}
