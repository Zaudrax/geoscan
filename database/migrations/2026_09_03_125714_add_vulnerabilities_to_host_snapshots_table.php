<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The known vulnerabilities attached to a host, as Shodan announces them.
 *
 * Two columns rather than a satellite table, for the same reason as open ports:
 * a snapshot is frozen in time, its lists are never queried on their own, and
 * one more table would buy nothing.
 *
 * Why a counter BESIDE the list: the list is deliberately capped (see
 * geoscan.vulnerabilities.max_stored), because a decoy can announce more than a
 * thousand. The counter stays exact -- otherwise the interface would lie about
 * the scale of what it is showing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('host_snapshots', function (Blueprint $table) {
            $table->json('vulnerabilities')->nullable()->after('web_technologies');
            $table->unsignedInteger('vulnerability_count')->default(0)->after('vulnerabilities');
        });
    }

    public function down(): void
    {
        Schema::table('host_snapshots', function (Blueprint $table) {
            $table->dropColumn(['vulnerabilities', 'vulnerability_count']);
        });
    }
};
