<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HISTORY: a new row on every fetch, never an update in place. This is
 * what makes the timeline of step 10 possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained()->cascadeOnDelete();
            $table->timestamp('fetched_at');

            // General information
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('organization')->nullable();
            $table->string('isp')->nullable();
            $table->string('asn', 32)->nullable();

            // Lists frozen in time -> JSON rather than satellite tables
            $table->json('hostnames')->nullable();
            $table->json('domains')->nullable();
            $table->json('web_technologies')->nullable();
            $table->json('open_ports')->nullable();

            // Last seen date as announced by Shodan itself
            $table->date('shodan_last_seen')->nullable();

            $table->timestamps();

            $table->index(['host_id', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_snapshots');
    }
};
