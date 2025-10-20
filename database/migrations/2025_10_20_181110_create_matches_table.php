<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->onDelete('cascade'); // Sala donde se juega
            $table->timestamp('started_at')->nullable(); // Cuándo empezó la partida
            $table->timestamp('finished_at')->nullable(); // Cuándo terminó la partida
            $table->unsignedBigInteger('winner_id')->nullable(); // Ganador (sin FK por dependencia circular con players)
            $table->json('game_state'); // Estado completo del juego (turnos, fase, datos específicos del juego)
            $table->timestamps();

            // Índices
            $table->index('room_id');
            $table->index(['room_id', 'started_at']); // Para consultas de historial
            $table->index('winner_id'); // Para consultas de ganadores
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
