<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A search = one scrape of the /search page at a point in time.
 * It is an archive: never updated, a new one is created instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('searches', function (Blueprint $table) {
            $table->id();
            $table->string('query');
            $table->unsignedBigInteger('total_results')->default(0);
            $table->timestamp('scraped_at');
            $table->timestamps();

            $table->index('scraped_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('searches');
    }
};
