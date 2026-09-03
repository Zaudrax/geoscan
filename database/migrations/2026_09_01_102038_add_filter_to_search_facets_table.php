<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shodan puts the exact filter token in every facet's href:
 *
 *     <a href="/search?query=nginx+org%3A%22Meteverse+Limited.%22">Meteverse Limited.</a>
 *
 * Rebuilding that token from the label is a trap (quotes, commas replaced by
 * spaces, casing). So we store the token exactly as Shodan wrote it: it is what
 * we append to the query to descend one level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_facets', function (Blueprint $table) {
            $table->string('filter')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('search_facets', function (Blueprint $table) {
            $table->dropColumn('filter');
        });
    }
};
