<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Player extends Model
{
    /**
     * Los atributos que se pueden asignar masivamente.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'match_id',
        'name',
        'role',
        'score',
        'is_connected',
        'last_ping',
    ];

    /**
     * Los atributos que deben ser casteados.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_connected' => 'boolean',
        'last_ping' => 'datetime',
        'score' => 'integer',
    ];

    /**
     * Relación: El jugador pertenece a una partida.
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    /**
     * Scope: Solo jugadores conectados.
     */
    public function scopeConnected($query)
    {
        return $query->where('is_connected', true);
    }

    /**
     * Scope: Solo jugadores desconectados.
     */
    public function scopeDisconnected($query)
    {
        return $query->where('is_connected', false);
    }

    /**
     * Actualizar el ping (heartbeat) del jugador.
     */
    public function ping(): void
    {
        $this->update([
            'is_connected' => true,
            'last_ping' => now(),
        ]);
    }

    /**
     * Marcar al jugador como desconectado.
     */
    public function disconnect(): void
    {
        $this->update(['is_connected' => false]);
    }

    /**
     * Reconectar al jugador.
     */
    public function reconnect(): void
    {
        $this->update([
            'is_connected' => true,
            'last_ping' => now(),
        ]);
    }

    /**
     * Agregar puntos al jugador.
     */
    public function addScore(int $points): void
    {
        $this->increment('score', $points);
    }

    /**
     * Establecer el rol del jugador.
     */
    public function assignRole(string $role): void
    {
        $this->update(['role' => $role]);
    }

    /**
     * Verificar si el jugador tiene un rol específico.
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Verificar si el jugador está inactivo (más de X minutos sin ping).
     */
    public function isInactive(int $minutes = 5): bool
    {
        if (!$this->last_ping) {
            return false;
        }

        return $this->last_ping->diffInMinutes(now()) > $minutes;
    }
}
