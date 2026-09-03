<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A host brought back by a search: the (IP, port) pair as it appeared on the
 * /search page, with its location and timestamp.
 *
 * Unlike a ScanResult (which comes from the splitting enumeration), it is
 * rattache a une Search et sert d'abord a l'inspection visuelle : c'est la
 * matiere de la revue « cameras candidates ».
 */
class SearchResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'search_id', 'ip', 'port', 'service_url', 'country_code', 'country',
        'city', 'organization', 'hostnames', 'tags', 'technologies', 'banner',
        'observed_at', 'position',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'hostnames' => 'array',
            'tags' => 'array',
            'technologies' => 'array',
            'observed_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }

    /**
     * The service URL as one would open it in a browser.
     *
     * We prefer the link Shodan captured (scheme included); failing that we
     * rebuild it from ip:port, with no way to guess http versus https.
     */
    public function serviceUrl(): ?string
    {
        if (filled($this->service_url)) {
            return $this->service_url;
        }

        if ($this->port === null) {
            return null;
        }

        $scheme = $this->port === 443 ? 'https' : 'http';

        return "{$scheme}://{$this->ip}:{$this->port}/";
    }
}
