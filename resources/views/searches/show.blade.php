@extends('layouts.app')
@section('title', 'Recherche « '.$search->query.' » — GeoScan')

@section('content')
    <h1 class="mono">{{ $search->query }}</h1>
    <p class="lede">
        Archive du {{ $search->scraped_at->translatedFormat('d/m/Y \à H:i:s') }} —
        <a href="{{ route('searches.index') }}">retour à l'historique</a>
    </p>

    <div class="card">
        <div class="total-label">Total des résultats</div>
        <div class="total">{{ number_format($search->total_results, 0, ',', ' ') }}</div>
    </div>

    @if ($facetsByType->isEmpty())
        <div class="card"><p class="empty">Aucun classement n'a été extrait pour cette recherche.</p></div>
    @else
        <div class="grid-2">
            @foreach ($facetsByType as $facets)
                @include('partials.facets', ['facets' => $facets])
            @endforeach
        </div>
    @endif

    <h2>Caméras candidates <span class="mono">({{ $results->count() }})</span></h2>
    @if ($results->isEmpty())
        <div class="card"><p class="empty">Aucun hôte individuel n'a été ramené pour cette recherche.</p></div>
    @else
        <p class="lede">
            Les hôtes de la première page de résultats, à passer en revue un à un.
            « Voir » charge le flux de <em>cette</em> caméra à la demande ; rien
            n'est diffusé tant que tu ne cliques pas.
        </p>
        <table class="candidates">
            <thead>
                <tr>
                    <th>IP:port</th>
                    <th>Ville</th>
                    <th>Organisation</th>
                    <th>Observé le</th>
                    <th>Direct</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($results as $result)
                    <tr>
                        <td>
                            <a href="{{ route('hosts.show', $result->ip) }}" class="mono">{{ $result->ip }}</a>@if ($result->port)<span class="mono">:{{ $result->port }}</span>@endif
                        </td>
                        <td>{{ $result->city ?? '—' }}{{ $result->country_code ? ' ('.$result->country_code.')' : '' }}</td>
                        <td>{{ $result->organization ?? '—' }}</td>
                        <td class="mono">{{ $result->observed_at?->format('d/m/Y H:i:s') ?? '—' }}</td>
                        <td>
                            @if ($url = $result->serviceUrl())
                                <button type="button" class="live-toggle" data-url="{{ $url }}">Voir</button>
                                <a href="{{ $url }}" target="_blank" rel="noopener noreferrer">ouvrir ↗</a>
                            @else
                                <span class="empty">port inconnu</span>
                            @endif
                        </td>
                    </tr>
                    @if ($result->serviceUrl())
                        <tr class="live-row" hidden>
                            <td colspan="5"><div class="live-slot"></div></td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>

        <script>
            document.querySelectorAll('.live-toggle').forEach(function (button) {
                button.addEventListener('click', function () {
                    var row = button.closest('tr').nextElementSibling;
                    var slot = row.querySelector('.live-slot');

                    if (!row.hidden) {
                        row.hidden = true;
                        slot.innerHTML = '';
                        button.textContent = 'Voir';
                        return;
                    }

                    // Loaded on demand: the iframe is only created on click, and
                    // only for the camera that was chosen.
                    var frame = document.createElement('iframe');
                    frame.src = button.dataset.url;
                    frame.loading = 'lazy';
                    frame.referrerPolicy = 'no-referrer';
                    slot.appendChild(frame);
                    row.hidden = false;
                    button.textContent = 'Masquer';
                });
            });
        </script>
    @endif

    <p class="footnote">
        Cette page est une <strong>archive</strong> : elle est servie entièrement
        depuis la base SQLite. La consulter ne déclenche aucune requête vers
        shodan.io, même si les chiffres réels ont changé depuis. En revanche,
        « Voir » établit une connexion directe de ton navigateur vers la caméra.
    </p>
@endsection

@section('head')
    <style>
        table.candidates { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        table.candidates th, table.candidates td { padding: .4rem .6rem; text-align: left; border-bottom: 1px solid #e2e2e2; vertical-align: top; }
        table.candidates th { font-size: .8rem; text-transform: uppercase; letter-spacing: .03em; color: #666; }
        .live-toggle { cursor: pointer; margin-right: .5rem; }
        .live-slot iframe { width: 100%; height: 420px; border: 1px solid #ccc; background: #000; }
    </style>
@endsection
