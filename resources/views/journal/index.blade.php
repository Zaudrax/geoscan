@extends('layouts.app')
@section('title', 'Journal de conformité — GeoScan')

@section('content')
    <h1>Journal de conformité</h1>
    <p class="lede">
        Chaque sortie réseau vers shodan.io, horodatée, avec le délai réellement
        observé avant elle. Une politique de crawl affirmée et une politique de
        crawl mesurée ne se valent pas.
    </p>

    <div class="grid-2">
        <div class="card">
            <h2>Politique en vigueur</h2>
            <dl class="info">
                <dt>User-Agent</dt>   <dd class="small mono">{{ $policy['user_agent'] }}</dd>
                <dt>Délai minimum</dt><dd>{{ $policy['delay'] }} s</dd>
                <dt>Timeout</dt>      <dd>{{ $policy['timeout'] }} s</dd>
                <dt>Interdits</dt>
                <dd class="mono">
                    @forelse ($policy['disallowed'] as $path){{ $path }}@if (! $loop->last), @endif @empty—@endforelse
                </dd>
            </dl>
            <p class="footnote">
                Les chemins interdits sont refusés par le client lui-même : la
                requête n'est jamais construite.
            </p>
        </div>

        <div class="card">
            <h2>Ce que le journal montre</h2>
            <dl class="info">
                <dt>Requêtes</dt>      <dd>{{ number_format($stats['total'], 0, ',', ' ') }}</dd>
                <dt>Bloquées</dt>      <dd>{{ $stats['blocked'] }}</dd>
                <dt>En erreur</dt>     <dd>{{ $stats['failed'] }}</dd>
                <dt>Délai le + court</dt>
                <dd>{{ $stats['shortest_gap'] !== null ? number_format($stats['shortest_gap'], 2, ',', ' ').' s' : '—' }}</dd>
                <dt>Première</dt>      <dd class="small">{{ $stats['first'] ? \Illuminate\Support\Carbon::parse($stats['first'])->format('d/m/Y H:i:s') : '—' }}</dd>
                <dt>Dernière</dt>      <dd class="small">{{ $stats['last'] ? \Illuminate\Support\Carbon::parse($stats['last'])->format('d/m/Y H:i:s') : '—' }}</dd>
            </dl>
            <p class="footnote">
                C'est le délai le plus COURT qui se confronte au
                « Crawl-delay: 10 » : une moyenne masquerait une rafale.
            </p>
        </div>
    </div>

    <div class="card">
        <h2>Requêtes ({{ $requests->total() }})</h2>
        @if ($requests->isEmpty())
            <p class="empty">Aucune requête sortante enregistrée.</p>
        @else
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>Horodatage</th><th>Chemin</th><th>Requête</th>
                        <th class="num">Attente</th><th class="num">Code</th><th>Issue</th><th>Session</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($requests as $request)
                        <tr>
                            <td class="mono small">{{ $request->sent_at->format('d/m/Y H:i:s') }}</td>
                            <td class="mono small">{{ $request->path }}</td>
                            <td class="small dim">{{ Str::limit($request->query, 60) ?: '—' }}</td>
                            <td class="num mono small">{{ $request->waited_seconds !== null ? number_format($request->waited_seconds, 2, ',', ' ').' s' : '—' }}</td>
                            <td class="num mono small">{{ $request->status ?? '—' }}</td>
                            <td>
                                <span class="chip small {{ $request->outcome === 'sent' ? '' : 'level-'.($request->wasBlocked() ? 'info' : 'eleve') }}">
                                    {{ $request->state_label }}
                                </span>
                                @if ($request->note)
                                    <div class="dim small">{{ $request->note }}</div>
                                @endif
                            </td>
                            <td class="small dim">{{ $request->authenticated ? 'connectée' : 'anonyme' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top:14px">{{ $requests->links() }}</div>
        @endif
    </div>
@endsection
