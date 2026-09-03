<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The journal of outbound requests.
 *
 * The assignment asks us to say, path by path, what robots.txt allows and to
 * hold a delay between requests. Until now we asserted it; this table PROVES
 * it. Every outbound call leaves a timestamped trace here, with the delay
 * actually observed before it -- which makes the crawl policy verifiable by a
 * third party rather than merely declared.
 *
 * We journal intent and outcome, never content: neither the HTML received nor
 * the cookies sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_requests', function (Blueprint $table) {
            $table->id();
            $table->string('service', 32)->default('shodan');
            $table->string('path');
            $table->text('query')->nullable();

            // Null when the request never went out: a robots.txt refusal, or a
            // network failure. The distinction matters: it is what shows the
            // guard actually bit.
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('outcome', 32);
            $table->string('note')->nullable();

            // The delay actually honoured before this request, in seconds.
            $table->decimal('waited_seconds', 8, 3)->nullable();
            $table->boolean('authenticated')->default(false);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['service', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_requests');
    }
};
