<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Turns a stored ISO 3166-1 alpha-2 code into a human readable country name.
 *
 * Scans and watches both target a country and both needed the same lookup, so
 * it lives here rather than being written twice. Exposed as an Eloquent
 * accessor, which means views read `$model->country_name` like any other
 * attribute and never have to know a lookup happened.
 *
 * @property-read string $country_name
 */
trait ResolvesCountryName
{
    /**
     * Falls back to the raw code when the country is unknown. Showing "ZZ" is
     * more useful than showing nothing: it tells us the code is the problem.
     */
    protected function countryName(): Attribute
    {
        return Attribute::get(
            fn (): string => config('countries.'.$this->country_code, $this->country_code)
        );
    }
}
