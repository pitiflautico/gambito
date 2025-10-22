<?php

namespace App\Services\Modules\SessionManager;

use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Support\Facades\Auth;

/**
 * Session Manager Module
 *
 * Gestiona la identificación del jugador actual en una partida,
 * manejando tanto usuarios autenticados como invitados (guests).
 *
 * Responsabilidades:
 * - Identificar el jugador actual basado en autenticación o sesión guest
 * - Obtener el Player asociado a un match
 * - Validar que el jugador pertenezca al match
 *
 * @see app/Services/Modules/SessionManager/SessionManager.md
 */
class SessionManager
{
    /**
     * Obtener el jugador actual de un match.
     *
     * Verifica primero si hay un usuario autenticado y busca el Player
     * asociado en el match. Si no hay usuario autenticado, busca por
     * guest_session_id.
     *
     * @param GameMatch $match El match en el que buscar el jugador
     * @return Player|null El jugador encontrado o null si no existe
     */
    public static function getCurrentPlayer(GameMatch $match): ?Player
    {
        // Caso 1: Usuario autenticado
        if (Auth::check()) {
            return $match->players()
                ->where('user_id', Auth::id())
                ->first();
        }

        // Caso 2: Guest con sesión
        if (session()->has('guest_session_id')) {
            $guestSessionId = session('guest_session_id');
            return $match->players()
                ->where('session_id', $guestSessionId)
                ->first();
        }

        // No hay sesión válida
        return null;
    }

    /**
     * Obtener el jugador actual y lanzar excepción si no existe.
     *
     * @param GameMatch $match El match en el que buscar el jugador
     * @return Player El jugador encontrado
     * @throws \Exception Si no se encuentra el jugador
     */
    public static function getCurrentPlayerOrFail(GameMatch $match): Player
    {
        $player = self::getCurrentPlayer($match);

        if (!$player) {
            throw new \Exception('No se encontró el jugador actual en esta partida');
        }

        return $player;
    }

    /**
     * Verificar si el jugador actual pertenece al match.
     *
     * @param GameMatch $match El match a verificar
     * @return bool True si el jugador pertenece al match
     */
    public static function isPlayerInMatch(GameMatch $match): bool
    {
        return self::getCurrentPlayer($match) !== null;
    }

    /**
     * Obtener el ID del jugador actual.
     *
     * @param GameMatch $match El match en el que buscar el jugador
     * @return int|null El ID del jugador o null si no existe
     */
    public static function getCurrentPlayerId(GameMatch $match): ?int
    {
        $player = self::getCurrentPlayer($match);
        return $player?->id;
    }

    /**
     * Obtener información de debug sobre la sesión actual.
     *
     * @return array Información de debug
     */
    public static function getDebugInfo(): array
    {
        return [
            'is_authenticated' => Auth::check(),
            'user_id' => Auth::id(),
            'has_guest_session' => session()->has('guest_session_id'),
            'guest_session_id' => session('guest_session_id'),
        ];
    }
}
