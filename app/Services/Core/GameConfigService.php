<?php

namespace App\Services\Core;

use App\Models\Game;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

/**
 * Servicio para gestionar configuraciones de juegos.
 *
 * Lee y cachea las configuraciones desde config.json de cada juego.
 */
class GameConfigService
{
    /**
     * Obtener la configuración completa de un juego.
     *
     * @param string $gameSlug Slug del juego (ej: 'pictionary')
     * @return array|null Configuración del juego o null si no existe
     */
    public function getConfig(string $gameSlug): ?array
    {
        $cacheKey = "game.config.{$gameSlug}";

        return Cache::remember($cacheKey, 3600, function () use ($gameSlug) {
            $configPath = base_path("games/{$gameSlug}/config.json");

            if (!File::exists($configPath)) {
                return null;
            }

            $content = File::get($configPath);
            return json_decode($content, true);
        });
    }

    /**
     * Obtener solo las configuraciones customizables de un juego.
     *
     * @param string $gameSlug Slug del juego
     * @return array Array de configuraciones customizables
     */
    public function getCustomizableSettings(string $gameSlug): array
    {
        $config = $this->getConfig($gameSlug);

        if (!$config || !isset($config['customizableSettings'])) {
            return [];
        }

        return $config['customizableSettings'];
    }

    /**
     * Obtener los valores por defecto de las configuraciones.
     *
     * @param string $gameSlug Slug del juego
     * @return array Array con key => default_value
     */
    public function getDefaults(string $gameSlug): array
    {
        $settings = $this->getCustomizableSettings($gameSlug);
        $defaults = [];

        foreach ($settings as $key => $setting) {
            $defaults[$key] = $setting['default'] ?? null;
        }

        return $defaults;
    }

    /**
     * Validar que un valor sea válido para un campo específico.
     *
     * @param string $gameSlug Slug del juego
     * @param string $fieldKey Key del campo
     * @param mixed $value Valor a validar
     * @return bool True si es válido
     */
    public function isValidValue(string $gameSlug, string $fieldKey, $value): bool
    {
        $settings = $this->getCustomizableSettings($gameSlug);

        if (!isset($settings[$fieldKey])) {
            return false;
        }

        $setting = $settings[$fieldKey];

        switch ($setting['type']) {
            case 'number':
                if (!is_numeric($value)) {
                    return false;
                }
                $value = (float) $value;
                return $value >= ($setting['min'] ?? PHP_INT_MIN)
                    && $value <= ($setting['max'] ?? PHP_INT_MAX);

            case 'select':
            case 'radio':
                $validValues = array_column($setting['options'], 'value');
                return in_array($value, $validValues, true);

            case 'checkbox':
                return is_bool($value) || $value === '1' || $value === '0' || $value === 1 || $value === 0;

            default:
                return false;
        }
    }

    /**
     * Generar reglas de validación para Laravel Validator.
     *
     * @param string $gameSlug Slug del juego
     * @return array Array de reglas de validación
     */
    public function getValidationRules(string $gameSlug): array
    {
        $settings = $this->getCustomizableSettings($gameSlug);
        $rules = [];

        foreach ($settings as $key => $setting) {
            $fieldRules = ['nullable'];

            switch ($setting['type']) {
                case 'number':
                    $fieldRules[] = 'integer';
                    if (isset($setting['min'])) {
                        $fieldRules[] = "min:{$setting['min']}";
                    }
                    if (isset($setting['max'])) {
                        $fieldRules[] = "max:{$setting['max']}";
                    }
                    break;

                case 'select':
                case 'radio':
                    $validValues = array_column($setting['options'], 'value');
                    $fieldRules[] = 'in:' . implode(',', $validValues);
                    break;

                case 'checkbox':
                    $fieldRules[] = 'boolean';
                    break;
            }

            $rules[$key] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Limpiar la cache de configuración de un juego.
     *
     * @param string $gameSlug Slug del juego
     * @return void
     */
    public function clearCache(string $gameSlug): void
    {
        Cache::forget("game.config.{$gameSlug}");
    }

    /**
     * Limpiar la cache de todos los juegos.
     *
     * @return void
     */
    public function clearAllCache(): void
    {
        $games = Game::all();

        foreach ($games as $game) {
            $this->clearCache($game->slug);
        }
    }

    /**
     * Verificar si un juego tiene configuraciones customizables.
     *
     * @param string $gameSlug Slug del juego
     * @return bool True si tiene configuraciones customizables
     */
    public function hasCustomizableSettings(string $gameSlug): bool
    {
        return !empty($this->getCustomizableSettings($gameSlug));
    }

    /**
     * Mergear configuraciones del usuario con defaults.
     *
     * @param string $gameSlug Slug del juego
     * @param array $userSettings Settings proporcionados por el usuario
     * @return array Settings completos con defaults aplicados
     */
    public function mergeWithDefaults(string $gameSlug, array $userSettings): array
    {
        $defaults = $this->getDefaults($gameSlug);
        return array_merge($defaults, array_filter($userSettings, fn($v) => $v !== null));
    }
}
