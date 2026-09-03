<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The individual hosts brought back by a search: one (IP, port) pair per row,
 * in the order Shodan listed them.
 *
 * Like the rankings, this is a frozen archive -- never updated, re-scraped into
 * a new search instead. This table feeds the "candidate cameras" view: enough to
 * review hosts one by one without
 * repasser par shodan.io.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_id')->constrained()->cascadeOnDelete();

            $table->string('ip', 45);
            $table->unsignedInteger('port')->nullable();
            // Le lien « ouvrir le service » de Shodan, schema compris.
            $table->string('service_url')->nullable();

            $table->string('country_code', 2)->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('organization')->nullable();

            $table->json('hostnames')->nullable();
            $table->json('tags')->nullable();
            $table->json('technologies')->nullable();
            $table->text('banner')->nullable();

            // Timestamp Shodan announced for this observation, to the second.
            $table->timestamp('observed_at')->nullable();

            // Rank within the result list: preserves the display order.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['search_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_results');
    }
};
