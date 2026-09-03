<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache de geocodage. Shodan ne donne aucune coordonnee : on resout
 * "city, country" exactly once through the geocoder, then never again.
 *
 * resolved_at nul + attempts > 0 = on a essaye et echoue ; on ne retente pas
 * en boucle a chaque affichage de carte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_points', function (Blueprint $table) {
            $table->id();

            $table->string('country_code', 2);
            $table->string('city')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // geocoder|country_centroid
            $table->string('source', 32)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->unique(['country_code', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_points');
    }
};
