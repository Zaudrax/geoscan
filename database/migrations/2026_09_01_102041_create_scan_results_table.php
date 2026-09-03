<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One harvested observation: an (IP, port) pair seen in the results.
 *
 * Le rattachement a hosts reutilise l'entite stable du TP -- une IP trouvee
 * by a scan links through to its host record, and back.
 *
 * La contrainte d'unicite (scan_id, ip, port) est le garde-fou anti-doublon :
 * the same IP necessarily reappears across several sub-queries of the split.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('host_id')->constrained()->cascadeOnDelete();

            $table->string('ip', 45);
            $table->unsignedInteger('port')->nullable();

            $table->string('country_code', 2)->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('organization')->nullable();
            $table->string('product')->nullable();

            $table->json('hostnames')->nullable();
            $table->json('tags')->nullable();
            $table->json('technologies')->nullable();
            $table->text('banner')->nullable();

            // Timestamp Shodan announced for THIS observation, to the second.
            $table->timestamp('observed_at')->nullable();

            /*
             * The same components, exploded into integers.
             *
             * Deliberate denormalisation: filtering "every observation at 9am,
             * whatever the date" through strftime() pins the engine down on a
             * full table scan and is not portable across database engines. Four
             * indexed columns answer with integer comparisons instead.
             */
            $table->date('observed_date')->nullable();
            $table->unsignedTinyInteger('observed_hour')->nullable();
            $table->unsignedTinyInteger('observed_minute')->nullable();
            $table->unsignedTinyInteger('observed_second')->nullable();

            // The sub-query that surfaced this result: the split's evidence.
            $table->string('matched_query')->nullable();

            $table->timestamps();

            $table->unique(['scan_id', 'ip', 'port']);
            $table->index(['scan_id', 'observed_at']);
            $table->index(['scan_id', 'observed_date']);
            $table->index(['scan_id', 'observed_hour']);
            $table->index(['scan_id', 'observed_second']);
            $table->index(['scan_id', 'port']);
            $table->index(['scan_id', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_results');
    }
};
