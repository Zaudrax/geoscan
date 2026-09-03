<?php

namespace App\Providers;

use App\Services\Shodan\Parsers\HostPageParser;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The parser is deliberately unaware of configuration: that is what
        // lets it be tested without booting the framework. So this is where the
        // application's value reaches it.
        $this->app->bind(HostPageParser::class, fn () => new HostPageParser(
            (int) config('geoscan.vulnerabilities.max_stored', 50),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
