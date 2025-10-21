<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    /**
     * Los atributos que se pueden asignar masivamente.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'path',
        'metadata',
        'is_premium',
        'is_active',
    ];

    /**
     * Los atributos que deben ser casteados.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array', // JSON a array asociativo (cache de config.json)
        'is_premium' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Relación: Un juego puede tener muchas salas.
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * Scope: Solo juegos activos.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Solo juegos premium.
     */
    public function scopePremium($query)
    {
        return $query->where('is_premium', true);
    }

    /**
     * Scope: Solo juegos gratuitos.
     */
    public function scopeFree($query)
    {
        return $query->where('is_premium', false);
    }

    /**
     * Obtener la configuración completa del juego desde su archivo config.json.
     * Si existe metadata cacheada, la usa; si no, carga desde el archivo.
     */
    public function getConfigAttribute(): ?array
    {
        // Si hay metadata cacheada, usarla
        if ($this->metadata) {
            return $this->metadata;
        }

        // Si no, intentar cargar desde el archivo config.json del módulo
        $configPath = base_path($this->path . '/config.json');
        if (file_exists($configPath)) {
            return json_decode(file_get_contents($configPath), true);
        }

        return null;
    }

    /**
     * Obtener las capacidades del juego desde capabilities.json.
     */
    public function getCapabilitiesAttribute(): ?array
    {
        $capabilitiesPath = base_path($this->path . '/capabilities.json');
        if (file_exists($capabilitiesPath)) {
            return json_decode(file_get_contents($capabilitiesPath), true);
        }

        return null;
    }

    /**
     * Obtener el número mínimo de jugadores.
     */
    public function getMinPlayersAttribute(): ?int
    {
        $config = $this->config;
        return $config['minPlayers'] ?? null;
    }

    /**
     * Obtener el número máximo de jugadores.
     */
    public function getMaxPlayersAttribute(): ?int
    {
        $config = $this->config;
        return $config['maxPlayers'] ?? null;
    }

    /**
     * Obtener la duración estimada.
     */
    public function getEstimatedDurationAttribute(): ?string
    {
        $config = $this->config;
        return $config['estimatedDuration'] ?? null;
    }

    /**
     * Verificar si un número de jugadores es válido para este juego.
     */
    public function isValidPlayerCount(int $count): bool
    {
        $min = $this->min_players ?? 1;
        $max = $this->max_players ?? 999;

        return $count >= $min && $count <= $max;
    }

    /**
     * Cachear la metadata del juego (para optimizar queries).
     * Lee el config.json del módulo y lo guarda en la columna metadata.
     */
    public function cacheMetadata(): void
    {
        $configPath = base_path($this->path . '/config.json');
        if (file_exists($configPath)) {
            $config = json_decode(file_get_contents($configPath), true);
            $this->update(['metadata' => $config]);
        }
    }

    /**
     * Verificar si el módulo del juego existe físicamente.
     */
    public function moduleExists(): bool
    {
        return is_dir(base_path($this->path));
    }

    /**
     * Obtener la clase del motor del juego (Engine).
     * Convierte el slug en el namespace del engine.
     * Ejemplo: "pictionary" -> "Games\Pictionary\PictionaryEngine"
     */
    public function getEngineClass(): ?string
    {
        // Capitalizar el slug para el nombre de la clase
        $className = ucfirst($this->slug);

        // Construir el namespace completo
        $engineClass = "Games\\{$className}\\{$className}Engine";

        return $engineClass;
    }

    /**
     * Obtener una instancia del motor del juego.
     */
    public function getEngine()
    {
        $engineClass = $this->getEngineClass();

        if (!$engineClass || !class_exists($engineClass)) {
            throw new \RuntimeException("Game engine not found for game: {$this->slug}");
        }

        return app($engineClass);
    }
}
