<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Cuts the suite off from the machine's .env.
         *
         * Without this, a machine where SHODAN_SESSION_COOKIE is set makes the
         * application look authenticated in EVERY test, and the suite stops
         * proving the same thing here and on the machine next door. Tests that
         * need a session opt into it themselves.
         */
        config()->set('geoscan.login', [
            'enabled' => false,
            'session_cookie' => null,
            'email' => null,
            'password' => null,
            'url' => 'https://account.shodan.io/login',
            'session_ttl' => 3600,
        ]);
    }
}
