<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchEvent extends Model
{
    /**
     * Deshabilitar updated_at (solo usamos created_at para logs).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Los atributos que se pueden asignar masivamente.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'match_id',
        'event_type',
        'data',
        'created_at',
    ];

    /**
     * Los atributos que deben ser casteados.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'data' => 'array', // JSON a array asociativo
        'created_at' => 'datetime',
    ];

    /**
     * Relación: El evento pertenece a una partida.
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    /**
     * Scope: Filtrar por tipo de evento.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    /**
     * Scope: Ordenar por más recientes primero.
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Crear un evento de forma estática (helper).
     */
    public static function log(int $matchId, string $type, array $data = []): self
    {
        return static::create([
            'match_id' => $matchId,
            'event_type' => $type,
            'data' => $data,
            'created_at' => now(),
        ]);
    }
}
