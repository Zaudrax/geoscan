@extends('layouts.app')
@section('title', 'Scan '.$scan->country_name.' — GeoScan')
@section('wrap-class', 'wrap-wide')

@section('head')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    @if ($scan->status === \App\Models\Scan::STATUS_RUNNING)
        {{-- The scan runs on the queue: the page refreshes itself until it ends. --}}
        <meta http-equiv="refresh" content="8">
    @endif
@endsection

@section('content')
    <div class="scan-head">
        <div>
            <h1>{{ $scan->country_name }} <span class="mono dim">{{ $scan->windowLabel() }}</span></h1>
            <p class="lede mono">{{ $scan->base_query }}</p>
        </div>
        <span class="badge badge-{{ $scan->status }}">{{ $scan->state_label }}</span>
    </div>

    @if ($scan->status === \App\Models\Scan::STATUS_RUNNING)
        <div class="alert alert-info">
            Scan en cours — {{ $scan->requests_used }} / {{ $scan->max_requests }} requêtes envoyées.
            À {{ config('geoscan.request_delay') }} s d'intervalle, compte plusieurs minutes.
            Si ce compteur ne bouge pas, le worker de la file n'est probablement pas
            lancé : <span class="mono">composer run dev</span>.
        </div>
    @endif

    @if ($scan->failure_reason)
        <div class="alert alert-error">{{ $scan->failure_reason }}</div>
    @endif

    <div class="stats">
        <div class="stat">
            <div class="stat-value">{{ number_format($scan->total_reported, 0, ',', ' ') }}</div>
            <div class="stat-label">annoncés par Shodan</div>
        </div>
        <div class="stat">
            <div class="stat-value accent">{{ $totalResults }}</div>
            <div class="stat-label">moissonnés en base</div>
        </div>
        <div class="stat">
            <div class="stat-value">{{ round($scan->coverage() * 100) }} %</div>
            <div class="stat-label">couverture</div>
        </div>
        <div class="stat">
            <div class="stat-value {{ $scan->beatTheCeiling() ? 'accent' : '' }}">{{ $scan->visibleCeiling() }}</div>
            <div class="stat-label">
                plafond Shodan
                @if ($scan->beatTheCeiling())
                    — dépassé de {{ $totalResults - $scan->visibleCeiling() }}
                @endif
            </div>
        </div>
        <div class="stat">
            <div class="stat-value">{{ $scan->requests_used }}<span class="dim">/{{ $scan->max_requests }}</span></div>
            <div class="stat-label">requêtes utilisées</div>
        </div>
    </div>

    @if ($markers === [])
        <div class="alert alert-info">
            Aucun point à placer sur la carte : soit le scan n'a encore rien
            moissonné, soit le géocodage n'a pas pu résoudre les villes
            (<span class="mono">GEOCODING_ENABLED</span>). Les coordonnées se
            resolvent au fil des affichages, {{ config('geoscan.geocoding.max_lookups') }}
            lieux à la fois.
        </div>
    @else
        <div id="map" class="map" data-markers="{{ json_encode($markers, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) }}"></div>
        <p class="footnote map-note">
            Position <strong>approximative</strong> : Shodan ne publie aucune
            coordonnée. Chaque point est le centre de la ville déclarée, résolu via
            Nominatim (OpenStreetMap), avec un décalage minime et stable pour que
            deux machines d'une même ville restent distinguables. Un marqueur
            creux signale un repli sur le centre du pays. La molette zoome tant
            que le curseur reste sur la carte.
        </p>
    @endif

    <div class="scan-body">
        <aside class="filters">
            <div class="card">
                <h2>Filtres</h2>
                <p class="explain">
                    Calculés sur nos propres lignes, pas sur Shodan. Cliquer ne
                    déclenche aucune requête sortante.
                </p>

                <form method="GET" action="{{ route('scans.show', $scan) }}" class="search-inline">
                    @foreach ($filters as $key => $value)
                        @continue($key === 'q')
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endforeach
                    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}"
                           placeholder="IP, bannière, organisation…" autocomplete="off">
                </form>

                @if ($filters !== [])
                    <div class="active-filters">
                        @foreach ($filters as $key => $value)
                            <a class="chip chip-active"
                               href="{{ request()->fullUrlWithoutQuery($key) }}">{{ $key }}: {{ $value }} ×</a>
                        @endforeach
                        <a class="reset" href="{{ route('scans.show', $scan) }}">Tout effacer</a>
                    </div>
                @endif
            </div>

            @forelse ($facets as $dimension => $values)
                <div class="card">
                    <h2>{{ \App\Models\ScanResult::FACET_DIMENSIONS[$dimension] }}</h2>
                    @php $max = collect($values)->max('count') ?: 1; @endphp
                    @foreach ($values as $facet)
                        @php $active = ($filters[$dimension] ?? null) == $facet['value']; @endphp
                        <a class="facet facet-link {{ $active ? 'is-active' : '' }}"
                           href="{{ $active
                                ? request()->fullUrlWithoutQuery($dimension)
                                : request()->fullUrlWithQuery([$dimension => $facet['value']]) }}">
                            <div class="facet-head">
                                <span class="facet-label">{{ $facet['label'] }}</span>
                                <span class="facet-count">{{ $facet['count'] }}</span>
                            </div>
                            <div class="bar"><i style="width: {{ max(2, round($facet['count'] / $max * 100, 1)) }}%"></i></div>
                        </a>
                    @endforeach
                </div>
            @empty
                <div class="card"><p class="empty">Aucun filtre : le scan n'a rien moissonné.</p></div>
            @endforelse
        </aside>

        <section class="results">
            <div class="card">
                <h2>
                    {{ $results->count() }} résultat{{ $results->count() > 1 ? 's' : '' }}
                    @if ($filters !== [])
                        <span class="dim">sur {{ $totalResults }}</span>
                    @endif
                </h2>

                @if ($results->isEmpty())
                    <p class="empty">
                        @if ($scan->status === \App\Models\Scan::STATUS_RUNNING)
                            Le scan n'a pas encore remonté de résultat.
                        @else
                            Aucun résultat pour ces filtres.
                        @endif
                    </p>
                @else
                    <div class="table-scroll">
                        <table>
                            <thead>
                            <tr>
                                <th>IP</th>
                                <th class="num">Port</th>
                                <th>Ville</th>
                                <th>Organisation</th>
                                <th>Observé le</th>
                                <th>Exposition</th>
                                <th>Tags</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($results as $result)
                                <tr>
                                    <td>
                                        <a class="mono" href="{{ route('hosts.show', $result->ip) }}">{{ $result->ip }}</a>
                                        @if ($result->hostnames)
                                            <div class="dim small">{{ $result->hostnames[0] }}</div>
                                        @endif
                                    </td>
                                    <td class="num mono">{{ $result->port ?? '—' }}</td>
                                    <td>{{ $result->city ?? '—' }}</td>
                                    <td class="small">{{ $result->organization ?? '—' }}</td>
                                    <td class="mono small">{{ $result->observed_at?->format('d/m/Y H:i:s') ?? '—' }}</td>
                                    <td>
                                        @php $exposure = $scorer->forScanResult($result); @endphp
                                        @if ($exposure->isNotable())
                                            <span class="chip level-{{ $exposure->level() }}"
                                                  title="{{ $exposure->worst()->title }} — {{ $exposure->worst()->why }}">
                                                {{ $exposure->levelLabel() }}
                                            </span>
                                        @else
                                            <span class="dim small">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @foreach ($result->tags ?? [] as $tag)
                                            <span class="chip small">{{ $tag }}</span>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <details class="card trace">
                <summary><h2>Trace de l'algorithme — {{ $steps->count() }} étapes</h2></summary>
                <p class="explain">
                    Une ligne par requête envoyée à Shodan. L'indentation est la
                    profondeur de découpage : chaque cran ajoute un filtre à la
                    requête du dessus.
                </p>
                @if ($steps->isEmpty())
                    <p class="empty">Aucune étape enregistrée pour l'instant.</p>
                @else
                    <table>
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Requête</th>
                            <th class="num">Annoncés</th>
                            <th class="num">Moissonnés</th>
                            <th class="num">Nouveaux</th>
                            <th>Décision</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($steps as $step)
                            <tr>
                                <td class="num dim">{{ $step->position }}</td>
                                <td>
                                    <span class="mono small" style="padding-left: {{ $step->depth * 16 }}px">
                                        @if ($step->applied_filter)
                                            <span class="dim">└</span> {{ $step->applied_filter }}
                                        @else
                                            {{ $step->query }}
                                        @endif
                                    </span>
                                    @if ($step->note)
                                        <div class="dim small" style="padding-left: {{ $step->depth * 16 + 14 }}px">{{ $step->note }}</div>
                                    @endif
                                </td>
                                <td class="num">{{ number_format($step->total_results, 0, ',', ' ') }}</td>
                                <td class="num">{{ $step->harvested ?: '—' }}</td>
                                <td class="num">{{ $step->new_hosts ?: '—' }}</td>
                                <td><span class="badge badge-step-{{ $step->decision }}">{{ $step->state_label }}</span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </details>
        </section>
    </div>

    @if ($markers !== [])
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
                integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
        <script>
            (() => {
                const container = document.getElementById('map');
                const markers = JSON.parse(container.dataset.markers);

                // Wheel zoom off by default: otherwise scrolling the page with
                // the cursor crossing the map would zoom instead of scroll.
                const map = L.map(container, { scrollWheelZoom: false });

                // It takes over while the cursor is over the map and hands
                // control back on leaving: page scrolling is never captured
                // anywhere else.
                container.addEventListener('mouseenter', () => map.scrollWheelZoom.enable());
                container.addEventListener('mouseleave', () => map.scrollWheelZoom.disable());

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                }).addTo(map);

                const escapeHtml = (value) => String(value ?? '')
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                const bounds = markers.map((marker) => {
                    // Cercle plein = ville resolue, creux = repli sur le centre du pays.
                    L.circleMarker([marker.latitude, marker.longitude], {
                        radius: 6,
                        weight: 2,
                        {{-- Same ink blue as the rest of the interface: the map
                             is part of the document, not a separate widget. --}}
                        color: '#1f4b87',
                        fillColor: marker.approximate ? 'transparent' : '#1f4b87',
                        fillOpacity: marker.approximate ? 0 : 0.7,
                    })
                        .bindPopup(`
                            <strong>${escapeHtml(marker.ip)}${marker.port ? ':' + escapeHtml(marker.port) : ''}</strong><br>
                            ${escapeHtml(marker.hostname ?? '')}<br>
                            ${escapeHtml(marker.city ?? '')} ${escapeHtml(marker.country ?? '')}<br>
                            ${escapeHtml(marker.organization ?? '')}<br>
                            <small>${escapeHtml(marker.observed_at ?? '')}</small><br>
                            <a href="${marker.url}">Fiche hôte</a>
                        `)
                        .addTo(map);

                    return [marker.latitude, marker.longitude];
                });

                map.fitBounds(bounds, { padding: [40, 40], maxZoom: 11 });
            })();
        </script>
    @endif

    <p class="footnote">
        Cette page ne sort jamais vers Shodan : filtres, carte et trace sont lus
        en base. C'est la même distinction qu'entre l'historique et une nouvelle
        recherche — consulter une archive n'est pas la refaire.
    </p>
@endsection
