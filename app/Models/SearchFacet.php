<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchFacet extends Model
{
    use HasFactory;

    /** Libelles lisibles des types de classement scrapes. */
    public const TYPE_LABELS = [
        'country' => 'Top pays',
        'city' => 'Top villes',
        'port' => 'Top ports',
        'org' => 'Top organisations',
        'product' => 'Top produits',
        'os' => "Top systèmes d'exploitation",
    ];

    protected $fillable = ['search_id', 'type', 'label', 'filter', 'count', 'position'];

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'position' => 'integer',
        ];
    }

    /** Un classement appartient a une recherche. */
    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? ucfirst($this->type);
    }
}
