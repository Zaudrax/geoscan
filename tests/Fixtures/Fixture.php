<?php

namespace Tests\Fixtures;

/** Copies locales de vraies pages shodan.io, capturees le 2026-08-31. */
class Fixture
{
    public static function searchPage(): string
    {
        return self::read('search-nginx.html');
    }

    public static function hostPage(): string
    {
        return self::read('host-8.8.8.8.html');
    }

    /**
     * Fiche reelle de 1.1.1.1 : plusieurs noms d'hote ET plusieurs domaines,
     * each structured differently in the HTML. This is the case that traps a
     * naive text() based parse.
     */
    public static function hostPageWithMultipleValues(): string
    {
        return self::read('host-1.1.1.1.html');
    }

    /**
     * A host page carrying known vulnerabilities.
     *
     * The block mirrors the real structure observed 2026-09-03: identifiers are
     * not in the DOM but in an inline script, as a JSON object keyed by CVE.
     *
     * @param  array<string, array{cvss: float, ports?: list<int>, summary?: string}>  $vulnerabilities
     */
    public static function hostPageWithVulnerabilities(array $vulnerabilities = []): string
    {
        $vulnerabilities = $vulnerabilities !== [] ? $vulnerabilities : [
            'CVE-2021-44228' => ['cvss' => 10.0, 'ports' => [8080], 'summary' => 'Log4Shell : execution de code a distance via JNDI.'],
            'CVE-2022-1609' => ['cvss' => 9.8, 'ports' => [443], 'summary' => 'Porte derobee dans un greffon WordPress.'],
            'CVE-2019-0211' => ['cvss' => 7.8, 'ports' => [80], 'summary' => 'Elevation de privileges dans Apache HTTP Server.'],
        ];

        $script = '<script>(() => { const VULNS = '
            .json_encode($vulnerabilities, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            .'; })();</script>';

        return str_replace('</body>', $script.'</body>', self::hostPage());
    }

    /** A search page stripped of its rankings, for edge cases. */
    public static function searchPageWithoutFacets(): string
    {
        return '<html><body><div class="summary"><h4 class="total-results">1,234</h4></div></body></html>';
    }

    /**
     * Vraie page /search/facet?query=…&facet=product, capturee le 2026-09-01
     * for country:"PL" port:80 on a given second.
     *
     * This is the page behind the "More..." link. Its structure has nothing in
     * common with the search page rankings, and that is precisely why it
     * deserves its own local copy.
     */
    public static function facetPage(): string
    {
        return self::read('facet-product.html');
    }

    /**
     * Builds a made-to-measure result page in shodan.io's format.
     *
     * The real local copies prove the selectors match real HTML. They cannot,
     * however, drive a total or a facet distribution: that is what this builder
     * is for, and it is indispensable for exercising the splitting algorithm on
     * chosen cases.
     *
     * @param  list<array{ip: string, port?: int, city?: string, org?: string, at?: string}>  $results
     * @param  array<string, list<array{label: string, filter: string, count: int}>>  $facets
     *                                                                                         libelle du classement ("Top Ports") => valeurs
     */
    public static function searchResultsPage(
        int $total,
        array $results = [],
        array $facets = [],
        ?int $nextPage = null,
    ): string {
        $html = '<html><body><div class="summary">';
        $html .= '<h4 class="total-results">'.number_format($total, 0, '.', ',').'</h4>';

        foreach ($facets as $heading => $values) {
            $html .= '<h6>'.$heading.'</h6><ul class="facet-list">';

            foreach ($values as $value) {
                $html .= sprintf(
                    '<li><a href="/search?query=%s" class="text-dark">%s</a><span>%s</span></li>',
                    urlencode('base '.$value['filter']),
                    e($value['label']),
                    number_format($value['count'], 0, '.', ','),
                );
            }

            $html .= '<li><a href="/search/facet?query=base&facet=port">More...</a></li></ul>';
        }

        $html .= '</div><div class="results">';

        foreach ($results as $result) {
            $html .= self::resultBlock($result);
        }

        $html .= '</div>';

        if ($nextPage !== null) {
            $html .= '<div class="pagination"><a href="/search?query=base&amp;page='.$nextPage.'">Next</a></div>';
        }

        return $html.'</body></html>';
    }

    /** @param  array{ip: string, port?: int, city?: string, org?: string, at?: string}  $result */
    private static function resultBlock(array $result): string
    {
        $ip = $result['ip'];
        $port = $result['port'] ?? 80;

        return sprintf(
            '<div class="result">'
                .'<div class="heading">'
                    .'<a href="/host/%1$s" class="title">titre</a>'
                    .'<a href="http://%1$s:%2$d" class="text-danger">lien</a>'
                    .'<div class="timestamp">%3$s</div>'
                .'</div>'
                .'<div class="result-container"><div class="result-details"><ul>'
                    .'<li class="hostnames">%1$s</li>'
                    .'<li><a href="/search?query=base+org%%3A%%22%4$s%%22" class="filter-link filter-org">%4$s</a></li>'
                    .'<li>'
                        .'<a href="/search?query=base+country%%3A%%22PL%%22" class="filter-link text-dark">Poland</a>,'
                        .'<a href="/search?query=base+city%%3A%%22%5$s%%22" class="filter-link text-dark">%5$s</a>'
                    .'</li>'
                    .'<li class="tags"><a href="#" class="tag">cloud</a></li>'
                .'</ul></div>'
                .'<div class="banner-data"><pre>HTTP/1.1 200 OK</pre></div>'
                .'</div></div>',
            $ip,
            $port,
            $result['at'] ?? '2026-09-01T09:13:03',
            e($result['org'] ?? 'Acme'),
            e($result['city'] ?? 'Warsaw'),
        );
    }

    private static function read(string $name): string
    {
        return file_get_contents(__DIR__.'/'.$name);
    }
}
