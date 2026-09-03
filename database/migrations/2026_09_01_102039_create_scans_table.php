<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scan = one enumeration campaign launched against a country at a given
 * timestamp. Unlike a search (one page, one instant), a scan is a tree of
 * sub-queries whose overall outcome we keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();

            // Criteres saisis par l'utilisateur
            $table->string('country_code', 2);
            $table->date('observed_on');
            $table->unsignedTinyInteger('observed_hour');
            $table->unsignedTinyInteger('observed_minute');
            // Null = we sweep the 60 seconds of the minute as a split dimension
            $table->unsignedTinyInteger('observed_second')->nullable();

            // The root Shodan query, exactly as it will be sent
            $table->string('base_query');

            /*
             * The run's outcome.
             * total_reported : what Shodan announces for the root query
             * unique_hosts   : what we actually harvested, de-duplicated
             * The gap between the two is the coverage, and it is rarely 100%:
             * facet pages never list every value.
             */
            $table->unsignedBigInteger('total_reported')->default(0);
            $table->unsignedInteger('unique_hosts')->default(0);
            $table->unsignedSmallInteger('requests_used')->default(0);
            $table->unsignedSmallInteger('max_requests')->default(0);

            // running|completed|budget_exhausted|failed
            $table->string('status', 24)->default('running');
            $table->text('failure_reason')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['country_code', 'observed_on']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
