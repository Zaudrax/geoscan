<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A watch: a search replayed on a fixed interval to see what shows up.
 *
 * A scan answers "what is there?". A watch answers "what is NEW?", which is the
 * question you actually ask when monitoring an attack surface.
 *
 * A watch stores no results of its own: it owns scans, and novelty comes from
 * comparing two successive ones. Duplicating results here would create two
 * truths for the same facts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watches', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('country_code', 2);
            $table->string('base_term');

            // In hours: readable in a form, and precise enough. A watch that
            // ran faster than the crawl delay would make no sense.
            $table->unsignedSmallInteger('interval_hours')->default(24);

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            // Indexed for the scheduler's question: which ones are due?
            $table->index(['is_active', 'last_run_at']);
        });

        Schema::table('scans', function (Blueprint $table) {
            // A scan started by hand belongs to no watch.
            $table->foreignId('watch_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('watch_id');
        });

        Schema::dropIfExists('watches');
    }
};
