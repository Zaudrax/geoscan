<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One search page scraped at a point in time, kept as an archive.
 *
 * Never updated: running the same query tomorrow creates a second row. That is
 * what makes the history meaningful rather than a moving snapshot.
 */
class Search extends Model
{
    use HasFactory;

    protected $fillable = ['query', 'total_results', 'scraped_at'];

    protected function casts(): array
    {
        return [
            'scraped_at' => 'datetime',
            'total_results' => 'integer',
        ];
    }

    /** A search owns several rankings, in Shodan's display order. */
    public function facets(): HasMany
    {
        return $this->hasMany(SearchFacet::class)->orderBy('position');
    }

    /** The individual hosts collected, in result page order. */
    public function results(): HasMany
    {
        return $this->hasMany(SearchResult::class)->orderBy('position');
    }

    /**
     * Rankings grouped by type, ready to render:
     * ['country' => Collection<SearchFacet>, 'port' => ..., ...]
     */
    public function facetsByType(): Collection
    {
        return $this->facets->groupBy('type');
    }
}
