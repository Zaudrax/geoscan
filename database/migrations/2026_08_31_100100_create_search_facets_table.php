<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un classement "Top ..." rattache a une recherche : type, libelle, compteur.
 * Une recherche a plusieurs facets (5 groupes x 5 lignes environ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_facets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_id')->constrained()->cascadeOnDelete();
            // country|city|port|org|product|os
            $table->string('type', 32);
            $table->string('label');
            $table->unsignedBigInteger('count')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['search_id', 'type', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_facets');
    }
};
