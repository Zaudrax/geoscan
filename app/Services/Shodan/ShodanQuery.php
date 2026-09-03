<?php

namespace App\Services\Shodan;

use Illuminate\Support\Carbon;

/**
 * An immutable Shodan query under construction.
 *
 * It carries two things of different natures:
 *
 *  - FILTERS ("country:\"PL\"", "port:8080"), which require a logged in
 *    account; Shodan flatly refuses to apply them for an anonymous visitor;
 *  - FREE TEXT, matched against the raw banner -- in practice the "Date:" line
 *    of an HTTP header, down to the second.
 *
 * Combining both is what makes enumeration possible: the second narrows a whole
 * country down to a few dozen results, and the filters cut that remainder into
 * slices of fewer than 20.
 */
readonly class ShodanQuery
{
    /**
     * @param  list<string>  $filters  "key:value" tokens, in the order added
     */
    private function __construct(
        private string $freeText,
        private array $filters,
    ) {}

    /**
     * The root query of a timestamped scan: a country, and the exact instant
     * Shodan stamped on the banner.
     *
     * Produces exactly what you would type into the search bar:
     *     country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT
     */
    public static function forCountryAt(string $countryCode, Carbon $moment): self
    {
        return new self(
            self::httpDate($moment),
            ['country:"'.strtoupper($countryCode).'"'],
        );
    }

    /** A query with no timestamp: the country on its own. */
    public static function forCountry(string $countryCode): self
    {
        return new self('', ['country:"'.strtoupper($countryCode).'"']);
    }

    /**
     * The root of a term scan: a country, and free text matched against the raw
     * banner -- typically a server line such as "Server: yawcam". Facet
     * splitting applies to it identically.
     *
     *     country:"SE" Server: yawcam
     */
    public static function forCountryTerm(string $countryCode, string $term): self
    {
        return new self(trim($term), ['country:"'.strtoupper($countryCode).'"']);
    }

    /**
     * The Date header of an HTTP response, in RFC 7231 format.
     *
     * Two details that are not details:
     *
     *  - day and month names come from date(), so they are English, which is
     *    exactly what the banner contains;
     *  - the instant is formatted AS IS, with no timezone conversion. The time
     *    entered is the one read in the banner, and that is already GMT.
     *    Converting it from the application timezone (Europe/Paris) would shift
     *    the query by two hours and bring back nothing.
     */
    public static function httpDate(Carbon $moment): string
    {
        return 'Date: '.$moment->format('D, d M Y H:i:s').' GMT';
    }

    /** Descends one level in the split. Leaves the current instance untouched. */
    public function withFilter(string $filter): self
    {
        return new self($this->freeText, [...$this->filters, $filter]);
    }

    /** @return list<string> */
    public function filters(): array
    {
        return $this->filters;
    }

    /** How many filters are applied, i.e. how deep the split has gone. */
    public function depth(): int
    {
        return count($this->filters) - 1;
    }

    public function toString(): string
    {
        return trim(implode(' ', [...$this->filters, $this->freeText]));
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
