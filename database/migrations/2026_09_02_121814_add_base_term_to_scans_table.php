<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opens the scan to a free text root.
 *
 * Jusqu'ici un scan partait d'un pays + un horodatage (« country:"SE" Date: … »).
 * base_term allows a different root -- a banner search such as
 * "Server: yawcam" -- which the engine splits in exactly the same way. The
 * timestamp components therefore become optional: a term scan has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->string('base_term')->nullable()->after('country_code');
            $table->date('observed_on')->nullable()->change();
            $table->unsignedTinyInteger('observed_hour')->nullable()->change();
            $table->unsignedTinyInteger('observed_minute')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn('base_term');
            $table->date('observed_on')->nullable(false)->change();
            $table->unsignedTinyInteger('observed_hour')->nullable(false)->change();
            $table->unsignedTinyInteger('observed_minute')->nullable(false)->change();
        });
    }
};
