<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The step by step trace of the algorithm: one row per request sent to Shodan,
 * with the decision taken. This is what makes working around the ceiling
 * demonstrable rather than magical -- the split tree can be read in the clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('position');
            $table->unsignedTinyInteger('depth')->default(0);

            $table->string('query');
            // The filter added on top of the parent level, e.g. port:8080
            $table->string('applied_filter')->nullable();

            $table->unsignedBigInteger('total_results')->default(0);
            $table->unsignedSmallInteger('harvested')->default(0);
            $table->unsignedSmallInteger('new_hosts')->default(0);

            // harvested|split|abandoned|budget_exhausted|failed
            $table->string('decision', 24);
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['scan_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_steps');
    }
};
