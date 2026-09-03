<?php

namespace App\Services\Exposure;

use App\Models\HostSnapshot;
use App\Models\ScanResult;

/**
 * Translates what we observed into readable exposure findings.
 *
 * WHAT THIS SERVICE CLAIMS: "this service is reachable from the internet and
 * deserves a look, for this reason".
 *
 * WHAT IT DOES NOT CLAIM: "this machine is vulnerable". An open port is not a
 * flaw, and Shodan itself can be wrong -- it even labels some machines as
 * decoys. The grid lives in config/exposure.php so that this judgement stays
 * readable and arguable.
 *
 * A pure function: no network calls, no writes. It only reads data already in
 * the database.
 */
class ExposureScorer
{
    /** The verdict for one service observed during a scan. */
    public function forScanResult(ScanResult $result): ExposureReport
    {
        return $this->rate(
            ports: array_filter([$result->port]),
            tags: $result->tags ?? [],
            banner: $result->banner,
        );
    }

    /**
     * The verdict for a host record, across all of its ports.
     *
     * Tags are not carried by the snapshot -- they come from SEARCH pages, not
     * from host pages. They therefore have to be supplied, otherwise a machine
     * Shodan considers a decoy would be presented as a critical risk while its
     * data is most likely fabricated.
     *
     * @param  list<string>  $tags
     */
    public function forSnapshot(HostSnapshot $snapshot, array $tags = []): ExposureReport
    {
        return $this->rate(
            ports: array_map('intval', $snapshot->open_ports ?? []),
            tags: $tags,
            banner: null,
            vulnerabilityCount: (int) $snapshot->vulnerability_count,
            vulnerabilities: $snapshot->vulnerabilities ?? [],
        );
    }

    /**
     * @param  list<int>  $ports
     * @param  list<string>  $tags
     * @param  list<array{id: string, cvss: float|null, summary: string|null}>  $vulnerabilities
     */
    private function rate(
        array $ports,
        array $tags = [],
        ?string $banner = null,
        int $vulnerabilityCount = 0,
        array $vulnerabilities = [],
    ): ExposureReport {
        return new ExposureReport([
            ...$this->portFindings($ports),
            ...$this->tagFindings($tags),
            ...$this->bannerFindings($banner),
            ...$this->vulnerabilityFindings($vulnerabilityCount, $vulnerabilities),
        ]);
    }

    /**
     * @param  list<int>  $ports
     * @return list<Finding>
     */
    private function portFindings(array $ports): array
    {
        $grid = (array) config('exposure.ports', []);
        $findings = [];

        foreach ($ports as $port) {
            if (! isset($grid[$port])) {
                continue;
            }

            $entry = $grid[$port];

            $findings[] = new Finding(
                level: $entry['level'],
                title: $entry['service'].' exposé (port '.$port.')',
                why: $entry['why'],
                detail: (string) $port,
            );
        }

        return $findings;
    }

    /**
     * @param  list<string>  $tags
     * @return list<Finding>
     */
    private function tagFindings(array $tags): array
    {
        $grid = (array) config('exposure.tags', []);
        $findings = [];

        foreach ($tags as $tag) {
            $key = strtolower((string) $tag);

            if (! isset($grid[$key])) {
                continue;
            }

            $findings[] = new Finding(
                level: $grid[$key]['level'],
                title: 'Étiquette Shodan : '.$key,
                why: $grid[$key]['why'],
                detail: $key,
            );
        }

        return $findings;
    }

    /**
     * What the raw banner gives away about itself.
     *
     * @return list<Finding>
     */
    private function bannerFindings(?string $banner): array
    {
        if (blank($banner)) {
            return [];
        }

        $findings = [];

        foreach ((array) config('exposure.banner_patterns', []) as $rule) {
            if (! preg_match($rule['pattern'], $banner, $matches)) {
                continue;
            }

            $findings[] = new Finding(
                level: $rule['level'],
                title: $rule['title'],
                why: $rule['why'],
                detail: isset($matches['detail']) ? trim($matches['detail']) : null,
            );
        }

        return $findings;
    }

    /**
     * The known vulnerabilities Shodan reports.
     *
     * The level follows the highest CVSS score, aligned on the NVD's own
     * thresholds (9.0+ critical, 7.0+ high, 4.0+ medium) rather than inventing
     * new ones.
     *
     * @param  list<array{id: string, cvss: float|null, summary: string|null}>  $vulnerabilities
     * @return list<Finding>
     */
    private function vulnerabilityFindings(int $count, array $vulnerabilities): array
    {
        if ($count === 0) {
            return [];
        }

        $scores = array_filter(array_column($vulnerabilities, 'cvss'), 'is_numeric');
        $highest = $scores === [] ? null : max(array_map('floatval', $scores));

        $level = match (true) {
            $highest === null => 'modere',
            $highest >= 9.0 => 'critique',
            $highest >= 7.0 => 'eleve',
            $highest >= 4.0 => 'modere',
            default => 'info',
        };

        $worst = $this->highestScoring($vulnerabilities);

        return [new Finding(
            level: $level,
            title: $count.' faille'.($count > 1 ? 's' : '').' connue'.($count > 1 ? 's' : '').' associée'.($count > 1 ? 's' : '').' à ce service',
            why: $highest === null
                ? 'Shodan associe des failles publiées aux versions détectées sur cet hôte'
                : 'Shodan associe des failles publiées aux versions détectées ; la plus grave est notée '.$highest.'/10 au CVSS',
            detail: $worst['id'] ?? null,
        )];
    }

    /**
     * @param  list<array{id: string, cvss: float|null, summary: string|null}>  $vulnerabilities
     * @return array{id?: string, cvss?: float|null, summary?: string|null}
     */
    private function highestScoring(array $vulnerabilities): array
    {
        usort(
            $vulnerabilities,
            fn (array $a, array $b) => ($b['cvss'] ?? 0) <=> ($a['cvss'] ?? 0),
        );

        return $vulnerabilities[0] ?? [];
    }
}
