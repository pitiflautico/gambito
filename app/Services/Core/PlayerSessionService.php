<?php

namespace App\Services\Core;

use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Servicio para gestionar sesiones de jugadores invitados.
 *
 * Los jugadores NO necesitan registrarse. Se crean sesiones temporales
 * que se identifican por un session_id almacenado en cookies/session.
 *
 * Responsabilidades:
 * - Crear jugadores invitados temporales
 * - Validar nombres de jugadores (duplicados, caracteres)
 * - Gestionar reconexiones
 * - Limpiar sesiones expiradas
 * - Mantener heartbeat/ping de jugadores
 */
class PlayerSessionService
{
    /**
     * Tiempo máximo de inactividad antes de marcar como desconectado (minutos).
     */
    protected const INACTIVITY_TIMEOUT = 5;

    /**
     * Longitud del session_id generado.
     */
    protected const SESSION_ID_LENGTH = 32;

    /**
     * Crear o recuperar un jugador invitado.
     *
     * @param GameMatch $match La partida a la que se une
     * @param string $name Nombre del jugador
     * @param string|null $sessionId Session ID del jugador (si es reconexión)
     * @return Player El jugador creado o recuperado
     * @throws \InvalidArgumentException Si el nombre es inválido
     */
    public function createOrRecoverPlayer(GameMatch $match, string $name, ?string $sessionId = null): Player
    {
        // Validar nombre
        $this->validatePlayerName($name);

        // Si hay session_id, intentar recuperar jugador existente
        if ($sessionId) {
            $player = $this->recoverPlayer($match, $sessionId);
            if ($player) {
                Log::info("Player reconnected", [
                    'player_id' => $player->id,
                    'name' => $player->name,
                    'match_id' => $match->id,
                ]);
                return $player;
            }
        }

        // Verificar que no haya duplicados de nombre en la partida
        if ($this->isNameTaken($match, $name)) {
            throw new \InvalidArgumentException("El nombre '{$name}' ya está en uso en esta partida");
        }

        // Generar nuevo session_id
        $newSessionId = $this->generateSessionId();

        // Crear nuevo jugador
        $player = Player::create([
            'match_id' => $match->id,
            'name' => $name,
            'session_id' => $newSessionId,
            'is_connected' => true,
            'last_ping' => now(),
        ]);

        // Guardar session_id en la sesión de Laravel
        Session::put('player_session_id', $newSessionId);
        Session::put('player_id', $player->id);

        Log::info("Player created", [
            'player_id' => $player->id,
            'name' => $player->name,
            'match_id' => $match->id,
        ]);

        return $player;
    }

    /**
     * Validar nombre de jugador.
     *
     * @param string $name Nombre a validar
     * @return void
     * @throws \InvalidArgumentException Si el nombre es inválido
     */
    protected function validatePlayerName(string $name): void
    {
        // Limpiar espacios
        $name = trim($name);

        // Verificar longitud
        if (strlen($name) < 2) {
            throw new \InvalidArgumentException('El nombre debe tener al menos 2 caracteres');
        }

        if (strlen($name) > 20) {
            throw new \InvalidArgumentException('El nombre no puede tener más de 20 caracteres');
        }

        // Verificar caracteres permitidos (letras, números, espacios, guiones)
        if (!preg_match('/^[a-zA-Z0-9áéíóúÁÉÍÓÚñÑ\s\-]+$/', $name)) {
            throw new \InvalidArgumentException('El nombre solo puede contener letras, números, espacios y guiones');
        }

        // Verificar palabras prohibidas (opcional)
        $bannedWords = ['admin', 'moderator', 'system', 'bot'];
        $nameLower = strtolower($name);
        foreach ($bannedWords as $word) {
            if (str_contains($nameLower, $word)) {
                throw new \InvalidArgumentException("El nombre no puede contener la palabra '{$word}'");
            }
        }
    }

    /**
     * Verificar si un nombre ya está en uso en una partida.
     *
     * @param GameMatch $match La partida
     * @param string $name Nombre a verificar
     * @return bool True si el nombre está en uso
     */
    public function isNameTaken(GameMatch $match, string $name): bool
    {
        return $match->players()
            ->where('name', 'LIKE', $name)
            ->where('is_connected', true)
            ->exists();
    }

    /**
     * Recuperar un jugador por su session_id.
     *
     * @param GameMatch $match La partida
     * @param string $sessionId Session ID
     * @return Player|null El jugador recuperado o null
     */
    protected function recoverPlayer(GameMatch $match, string $sessionId): ?Player
    {
        $player = $match->players()
            ->where('session_id', $sessionId)
            ->first();

        if ($player) {
            // Marcar como reconectado
            $player->update([
                'is_connected' => true,
                'last_ping' => now(),
            ]);
        }

        return $player;
    }

    /**
     * Generar un session_id único.
     *
     * @return string Session ID generado
     */
    protected function generateSessionId(): string
    {
        return Str::random(self::SESSION_ID_LENGTH);
    }

    /**
     * Actualizar heartbeat/ping de un jugador.
     *
     * @param Player $player El jugador
     * @return void
     */
    public function ping(Player $player): void
    {
        $player->ping();
    }

    /**
     * Marcar un jugador como desconectado.
     *
     * @param Player $player El jugador
     * @return void
     */
    public function disconnect(Player $player): void
    {
        $player->update([
            'is_connected' => false,
            'last_ping' => now(),
        ]);

        Log::info("Player disconnected", [
            'player_id' => $player->id,
            'name' => $player->name,
            'match_id' => $player->match_id,
        ]);
    }

    /**
     * Detectar y marcar jugadores inactivos como desconectados.
     *
     * @param GameMatch $match La partida
     * @param int $timeoutMinutes Minutos de inactividad antes de desconectar
     * @return int Número de jugadores desconectados
     */
    public function detectInactivePlayers(GameMatch $match, int $timeoutMinutes = self::INACTIVITY_TIMEOUT): int
    {
        $cutoffTime = now()->subMinutes($timeoutMinutes);

        $inactivePlayers = $match->players()
            ->where('is_connected', true)
            ->where('last_ping', '<', $cutoffTime)
            ->get();

        foreach ($inactivePlayers as $player) {
            $this->disconnect($player);
        }

        if ($inactivePlayers->count() > 0) {
            Log::info("Detected inactive players", [
                'match_id' => $match->id,
                'count' => $inactivePlayers->count(),
                'timeout_minutes' => $timeoutMinutes,
            ]);
        }

        return $inactivePlayers->count();
    }

    /**
     * Obtener el jugador actual de la sesión.
     *
     * @return Player|null El jugador de la sesión actual o null
     */
    public function getCurrentPlayer(): ?Player
    {
        $playerId = Session::get('player_id');

        if (!$playerId) {
            return null;
        }

        return Player::find($playerId);
    }

    /**
     * Verificar si hay un jugador en la sesión actual.
     *
     * @return bool True si hay un jugador en la sesión
     */
    public function hasActiveSession(): bool
    {
        return Session::has('player_session_id') && Session::has('player_id');
    }

    /**
     * Limpiar la sesión del jugador.
     *
     * @return void
     */
    public function clearSession(): void
    {
        Session::forget('player_session_id');
        Session::forget('player_id');
    }

    /**
     * Asignar un rol a un jugador.
     *
     * @param Player $player El jugador
     * @param string $role El rol a asignar
     * @return void
     */
    public function assignRole(Player $player, string $role): void
    {
        $player->update(['role' => $role]);

        Log::info("Role assigned to player", [
            'player_id' => $player->id,
            'name' => $player->name,
            'role' => $role,
        ]);
    }

    /**
     * Actualizar puntuación de un jugador.
     *
     * @param Player $player El jugador
     * @param int $points Puntos a sumar (puede ser negativo)
     * @return void
     */
    public function updateScore(Player $player, int $points): void
    {
        $newScore = $player->score + $points;
        $player->update(['score' => $newScore]);

        Log::info("Player score updated", [
            'player_id' => $player->id,
            'name' => $player->name,
            'old_score' => $player->score,
            'points' => $points,
            'new_score' => $newScore,
        ]);
    }

    /**
     * Obtener ranking de jugadores en una partida.
     *
     * @param GameMatch $match La partida
     * @return \Illuminate\Database\Eloquent\Collection Jugadores ordenados por puntuación
     */
    public function getRanking(GameMatch $match)
    {
        return $match->players()
            ->orderBy('score', 'desc')
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Limpiar jugadores de partidas antiguas finalizadas.
     *
     * @param int $hoursOld Número de horas para considerar antigua
     * @return int Número de jugadores eliminados
     */
    public function cleanupOldPlayers(int $hoursOld = 24): int
    {
        $cutoffTime = now()->subHours($hoursOld);

        $count = Player::whereHas('match', function ($query) use ($cutoffTime) {
            $query->where('finished_at', '<', $cutoffTime);
        })->delete();

        Log::info("Cleaned up old players", [
            'count' => $count,
            'hours_old' => $hoursOld,
        ]);

        return $count;
    }

    /**
     * Obtener estadísticas de un jugador.
     *
     * @param Player $player El jugador
     * @return array Estadísticas del jugador
     */
    public function getPlayerStats(Player $player): array
    {
        return [
            'id' => $player->id,
            'name' => $player->name,
            'role' => $player->role,
            'score' => $player->score,
            'is_connected' => $player->is_connected,
            'last_ping' => $player->last_ping?->toIso8601String(),
            'is_inactive' => $player->isInactive(),
            'created_at' => $player->created_at->toIso8601String(),
        ];
    }
}
