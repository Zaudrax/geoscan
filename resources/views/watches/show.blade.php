@extends('layouts.app')
@section('title', $watch->label.' — GeoScan')

@section('content')
    <h1>{{ $watch->label }}</h1>
    <p class="lede mono">{{ $watch->shodanQuery() }}</p>

    <div class="card">
        <p class="small">
            {{ $watch->country_name }} — rejouée toutes les {{ $watch->interval_hours }} h —
            {{ $watch->last_run_at ? 'dernier passage le '.$watch->last_run_at->format('d/m/Y H:i') : 'jamais exécutée' }}
        </p>
        <form method="POST" action="{{ route('watches.toggle', $watch) }}">
            @csrf
            <button type="submit">{{ $watch->is_active ? 'Suspendre' : 'Reprendre' }}</button>
        </form>
    </div>

    {{-- ---------- What this page exists for ---------- --}}
    <div class="card">
        <h2>Apparus depuis le passage précédent</h2>
        @if ($watch->scans->count() < 2)
            <p class="empty">
                Il faut deux passages pour pouvoir comparer. Le premier scan est
                une référence, pas une découverte.
            </p>
        @elseif ($newcomers->isEmpty())
            <p class="empty">Rien de nouveau depuis le passage précédent.</p>
        @else
            <p class="lede">
                {{ $newcomers->count() }} service{{ $newcomers->count() > 1 ? 's' : '' }}
                visible{{ $newcomers->count() > 1 ? 's' : '' }} aujourd'hui et absent{{ $newcomers->count() > 1 ? 's' : '' }} du passage précédent.
            </p>
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr><th>IP</th><th class="num">Port</th><th>Ville</th><th>Organisation</th><th>Exposition</th></tr>
                    </thead>
                    <tbody>
                    @php $scorer = app(App\Services\Exposure\ExposureScorer::class); @endphp
                    @foreach ($newcomers as $result)
                        @php $exposure = $scorer->forScanResult($result); @endphp
                        <tr>
                            <td><a class="mono" href="{{ route('hosts.show', $result->ip) }}">{{ $result->ip }}</a></td>
                            <td class="num mono">{{ $result->port ?? '—' }}</td>
                            <td>{{ $result->city ?? '—' }}</td>
                            <td class="small">{{ $result->organization ?? '—' }}</td>
                            <td>
                                @if ($exposure->isNotable())
                                    <span class="chip level-{{ $exposure->level() }}"
                                          title="{{ $exposure->worst()->why }}">{{ $exposure->levelLabel() }}</span>
                                @else
                                    <span class="dim small">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h2>Passages ({{ $watch->scans->count() }})</h2>
        @forelse ($watch->scans as $scan)
            <div class="small" style="padding:6px 0">
                <a href="{{ route('scans.show', $scan) }}">{{ $scan->started_at->format('d/m/Y H:i') }}</a>
                — {{ $scan->unique_hosts }} hôte(s) sur {{ $scan->total_reported }} annoncé(s)
                <span class="chip small">{{ $scan->state_label }}</span>
            </div>
        @empty
            <p class="empty">Aucun passage pour l'instant.</p>
        @endforelse
    </div>
@endsection
