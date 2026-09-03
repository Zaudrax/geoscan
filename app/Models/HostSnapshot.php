<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photograph of a host record at one precise instant. Immutable by
 * convention: we never edit a snapshot, we create a new one.
 *
 * This is what makes the timeline meaningful. An IP that changed organisation
 * or opened a port between two visits shows that change because both states
 * still exist side by side.
 */
class HostSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'host_id', 'fetched_at', 'country', 'city', 'organization', 'isp',
        'asn', 'hostnames', 'domains', 'web_technologies', 'open_ports',
        'shodan_last_seen', 'vulnerabilities', 'vulnerability_count',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'shodan_last_seen' => 'date',
            'hostnames' => 'array',
            'domains' => 'array',
            'web_technologies' => 'array',
            'open_ports' => 'array',
            'vulnerabilities' => 'array',
            'vulnerability_count' => 'integer',
        ];
    }

    /** Un instantane appartient a un hote. */
    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    /** Fields tracked over time, with the label shown in the interface. */
    public const WATCHED_FIELDS = [
        'country' => 'pays',
        'city' => 'ville',
        'organization' => 'organisation',
        'isp' => 'FAI',
        'asn' => 'ASN',
        'hostnames' => "noms d'hôte",
        'domains' => 'domaines',
        'open_ports' => 'ports ouverts',
        'web_technologies' => 'technologies web',
        'vulnerability_count' => 'failles connues',
    ];

    /**
     * Fields whose value is a list. For those, saying "it changed" is useless:
     * what matters is WHICH entry appeared or disappeared.
     */
    private const LIST_FIELDS = ['hostnames', 'domains', 'open_ports', 'web_technologies'];

    /**
     * What changed against a previous snapshot, entry by entry.
     *
     * This is the heart of attack surface monitoring: between two visits, a
     * port can open. "The ports changed" does not read; "3389 appeared" does,
     * and is immediately understood as an exposed RDP service.
     *
     * @return list<array{field: string, label: string, kind: string, added?: list<string>, removed?: list<string>, from?: mixed, to?: mixed}>
     */
    public function changesSince(?self $previous): array
    {
        if (! $previous) {
            return [];
        }

        $changes = [];

        foreach (self::WATCHED_FIELDS as $field => $label) {
            $change = in_array($field, self::LIST_FIELDS, true)
                ? $this->listChange($field, $label, $previous)
                : $this->scalarChange($field, $label, $previous);

            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /**
     * Entries that appeared and disappeared between two lists.
     *
     * @return array{field: string, label: string, kind: string, added: list<string>, removed: list<string>}|null
     */
    private function listChange(string $field, string $label, self $previous): ?array
    {
        $before = $this->itemsOf($previous->{$field});
        $after = $this->itemsOf($this->{$field});

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added === [] && $removed === []) {
            return null;
        }

        return [
            'field' => $field,
            'label' => $label,
            'kind' => 'list',
            'added' => $added,
            'removed' => $removed,
        ];
    }

    /**
     * The old and the new value of a scalar field.
     *
     * @return array{field: string, label: string, kind: string, from: mixed, to: mixed}|null
     */
    private function scalarChange(string $field, string $label, self $previous): ?array
    {
        if ($previous->{$field} === $this->{$field}) {
            return null;
        }

        return [
            'field' => $field,
            'label' => $label,
            'kind' => 'scalar',
            'from' => $previous->{$field},
            'to' => $this->{$field},
        ];
    }

    /**
     * Makes a list comparable: every entry becomes a displayable string,
     * duplicates are dropped, and the order is fixed -- Shodan can return the
     * same hostnames in a different order from one visit to the next, and that
     * is not a change.
     *
     * Web technologies are objects; their name is what identifies them.
     *
     * @return list<string>
     */
    private function itemsOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = array_map(
            fn (mixed $item): string => is_array($item)
                ? (string) ($item['name'] ?? json_encode($item))
                : (string) $item,
            $value,
        );

        $items = array_values(array_unique(array_filter($items, fn (string $item) => $item !== '')));
        sort($items);

        return $items;
    }
}
