@extends('layouts.app')
@section('title', 'Hôte '.$host->ip.' — GeoScan')

@section('content')
    <h1 class="mono">{{ $host->ip }}</h1>
    <p class="lede">
        {{ $snapshots->count() }} instantané{{ $snapshots->count() > 1 ? 's' : '' }} en base —
        <a href="{{ route('hosts.create') }}">retour aux fiches hôte</a>
    </p>

    @if ($error)
        <div class="alert alert-error">{{ $error }}</div>
    @elseif ($reused)
        <div class="alert alert-info">
            Instantané récent réutilisé (cooldown de {{ config('geoscan.host_cooldown') }} s) :
            aucune requête n'a été envoyée à shodan.io pour cette visite.
        </div>
    @else
        <div class="alert alert-success">Nouvel instantané récupéré à l'instant.</div>
    @endif

    @if (! $current)
        <div class="card"><p class="empty">Aucune donnée disponible pour cet hôte.</p></div>
    @else
        {{-- ---------- Etat le plus recent ---------- --}}
        <div class="grid-2">
            <div class="card">
                <h2>Informations générales</h2>
                <dl class="info">
                    <dt>Pays</dt>          <dd>{{ $current->country ?? '—' }}</dd>
                    <dt>Ville</dt>         <dd>{{ $current->city ?? '—' }}</dd>
                    <dt>Organisation</dt>  <dd>{{ $current->organization ?? '—' }}</dd>
                    <dt>FAI</dt>           <dd>{{ $current->isp ?? '—' }}</dd>
                    <dt>ASN</dt>           <dd class="mono">{{ $current->asn ?? '—' }}</dd>
                    <dt>Vu par Shodan</dt> <dd>{{ $current->shodan_last_seen?->format('d/m/Y') ?? '—' }}</dd>
                    <dt>Récupéré le</dt>   <dd>{{ $current->fetched_at->format('d/m/Y H:i:s') }}</dd>
                </dl>
            </div>

            <div class="card">
                {{-- A decoy can advertise several hundred ports. Rendering them
                     all turns the page into a wall and buries everything below
                     it, so the list is capped and the remainder is counted. --}}
                @php
                    $ports = $current->open_ports ?? [];
                    $shownPorts = array_slice($ports, 0, 48);
                @endphp
                <h2>Ports ouverts ({{ count($ports) }})</h2>
                @forelse ($shownPorts as $port)
                    <span class="chip">{{ $port }}</span>
                @empty
                    <p class="empty">Aucun port ouvert relevé.</p>
                @endforelse
                @if (count($ports) > count($shownPorts))
                    <p class="footnote" style="margin-top:8px">
                        et {{ count($ports) - count($shownPorts) }} autres.
                        Les ports qui appellent une remarque sont repris dans
                        « Exposition » ci-dessous.
                    </p>
                @endif

                <h2 style="margin-top:22px">Noms d'hôte</h2>
                @forelse ($current->hostnames ?? [] as $hostname)
                    <span class="chip">{{ $hostname }}</span>
                @empty
                    <p class="empty">Aucun nom d'hôte.</p>
                @endforelse

                <h2 style="margin-top:22px">Domaines</h2>
                @forelse ($current->domains ?? [] as $domain)
                    <span class="chip">{{ $domain }}</span>
                @empty
                    <p class="empty">Aucun domaine.</p>
                @endforelse
            </div>
        </div>

        {{-- ---------- Exposition ---------- --}}
        @php $exposure = app(App\Services\Exposure\ExposureScorer::class)->forSnapshot($current, $tags); @endphp
        <div class="card">
            <h2>Exposition</h2>
            @if ($exposure->isEmpty())
                <p class="empty">Aucun constat : les ports ouverts relevés n'appellent pas de remarque particulière.</p>
            @else
                <p class="lede" style="margin-bottom:16px">
                    Niveau retenu : <span class="chip level-{{ $exposure->level() }}">{{ $exposure->levelLabel() }}</span>
                    &mdash; celui du constat le plus grave, jamais une somme.
                </p>
                <ul class="findings">
                    @foreach ($exposure->sorted() as $finding)
                        <li>
                            <span class="chip level-{{ $finding->level }}">{{ $finding->levelLabel() }}</span>
                            <strong>{{ $finding->title }}</strong>
                            <div class="finding-why">{{ $finding->why }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="footnote" style="margin-top:14px">
                « Exposition » veut dire surface d'attaque offerte, pas machine
                compromise. Un port ouvert n'est pas une faille.
            </p>
        </div>

        {{-- ---------- Failles connues ---------- --}}
        @if ($current->vulnerability_count > 0)
            <div class="card">
                <h2>Failles connues ({{ $current->vulnerability_count }})</h2>
                <p class="lede">
                    Failles publiées que Shodan associe aux versions détectées sur
                    cet hôte. Ce sont des correspondances de version, pas des
                    exploitations vérifiées.
                    @if ($current->vulnerability_count > count($current->vulnerabilities ?? []))
                        Les {{ count($current->vulnerabilities) }} plus graves sont conservées.
                    @endif
                </p>
                <table>
                    <thead>
                        <tr><th>CVE</th><th>CVSS</th><th>Résumé</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($current->vulnerabilities ?? [] as $vulnerability)
                            <tr>
                                <td class="mono">
                                    <a href="{{ config('geoscan.vulnerabilities.reference_url').$vulnerability['id'] }}"
                                       target="_blank" rel="noopener noreferrer">{{ $vulnerability['id'] }}</a>
                                </td>
                                <td class="mono">{{ $vulnerability['cvss'] ?? '—' }}</td>
                                <td>{{ $vulnerability['summary'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="card">
            <h2>Technologies web détectées</h2>
            @forelse (collect($current->web_technologies ?? [])->groupBy('category') as $category => $technologies)
                <div style="margin-bottom:10px">
                    <div class="total-label">{{ $category }}</div>
                    @foreach ($technologies as $technology)
                        <span class="chip">{{ $technology['name'] }}</span>
                    @endforeach
                </div>
            @empty
                <p class="empty">Aucune technologie web détectée.</p>
            @endforelse
        </div>

        {{-- ---------- Ligne du temps ---------- --}}
        <div class="card">
            <h2>Ligne du temps ({{ $snapshots->count() }} instantané{{ $snapshots->count() > 1 ? 's' : '' }})</h2>
            <ul class="timeline">
                @foreach ($snapshots as $index => $snapshot)
                    @php $previous = $snapshots->get($index + 1); @endphp
                    <li class="{{ $snapshot->is($current) ? 'current' : '' }}">
                        <time>{{ $snapshot->fetched_at->format('d/m/Y H:i:s') }}</time>
                        @if ($snapshot->is($current))
                            <span class="chip port" style="margin-left:8px">actuel</span>
                        @endif
                        <div class="snap-body">
                            {{ collect([$snapshot->organization, $snapshot->city, $snapshot->country])->filter()->join(' · ') ?: 'Informations générales absentes' }}
                            — {{ count($snapshot->open_ports ?? []) }} port(s) ouvert(s)
                        </div>
                        @php $changes = $snapshot->changesSince($previous); @endphp
                        @if ($changes)
                            <ul class="changes">
                                @foreach ($changes as $change)
                                    <li>
                                        <span class="change-field">{{ $change['label'] }}</span>
                                        @if ($change['kind'] === 'list')
                                            @foreach ($change['added'] as $item)
                                                <span class="chip added">+ {{ $item }}</span>
                                            @endforeach
                                            @foreach ($change['removed'] as $item)
                                                <span class="chip removed">&minus; {{ $item }}</span>
                                            @endforeach
                                        @else
                                            <span class="chip removed">{{ $change['from'] ?? '—' }}</span>
                                            <span class="change-arrow">&rarr;</span>
                                            <span class="chip added">{{ $change['to'] ?? '—' }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="footnote">
        Un instantané n'est jamais modifié après coup : la ligne du temps compare
        chaque instantané au précédent et nomme ce qui a bougé, entrée par entrée.
        Un port qui apparaît entre deux visites est un service qui vient d'être
        exposé &mdash; c'est le signal que cette page existe pour rendre visible.
    </p>
@endsection
