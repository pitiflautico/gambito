<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Arregla el constraint UNIQUE de session_id para permitir que un guest
     * pueda estar en múltiples partidas (histórico), pero solo UNA VEZ por partida.
     *
     * ANTES: session_id UNIQUE (global)
     * DESPUÉS: UNIQUE(match_id, session_id) - único por partida
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            // Eliminar constraint UNIQUE individual de session_id
            $table->dropUnique('players_session_id_unique');

            // Agregar constraint UNIQUE compuesto (match_id, session_id)
            // Esto permite que un guest esté en múltiples partidas,
            // pero solo puede crear UN jugador por partida
            $table->unique(['match_id', 'session_id'], 'players_match_session_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            // Eliminar constraint compuesto
            $table->dropUnique('players_match_session_unique');

            // Restaurar constraint UNIQUE individual (estado anterior)
            $table->unique('session_id');
        });
    }
};
