{{-- Un groupe de classement affiche en barres proportionnelles au plus gros compteur. --}}
@php $max = $facets->max('count') ?: 1; @endphp

<div class="card">
    <h2>{{ $facets->first()->typeLabel() }}</h2>
    @foreach ($facets as $facet)
        <div class="facet">
            <div class="facet-head">
                <span class="facet-label">{{ $facet->label }}</span>
                <span class="facet-count">{{ number_format($facet->count, 0, ',', ' ') }}</span>
            </div>
            <div class="bar"><i style="width: {{ max(2, round($facet->count / $max * 100, 1)) }}%"></i></div>
        </div>
    @endforeach
</div>
