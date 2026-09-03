@extends('layouts.app')
@section('title', 'Scans — GeoScan')

@section('content')
    <h1>Scans par pays</h1>
    <p class="lede">Les campagnes d'énumération déjà menées, de la plus récente à la plus ancienne.</p>

    <div class="card">
        @if ($scans->isEmpty())
            <p class="empty">
                Aucun scan pour l'instant —
                <a href="{{ route('scans.create') }}">lances-en un</a>.
            </p>
        @else
            <table>
                <thead>
                <tr>
                    <th>Pays</th>
                    <th>Instant visé</th>
                    <th class="num">Annoncés</th>
                    <th class="num">Moissonnés</th>
                    <th class="num">Couverture</th>
                    <th class="num">Requêtes</th>
                    <th>Statut</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($scans as $scan)
                    <tr>
                        <td><a href="{{ route('scans.show', $scan) }}">{{ $scan->country_name }}</a></td>
                        <td class="mono">{{ $scan->windowLabel() }}</td>
                        <td class="num">{{ number_format($scan->total_reported, 0, ',', ' ') }}</td>
                        <td class="num">
                            {{ $scan->results_count }}
                            @if ($scan->beatTheCeiling())
                                <span class="beat" title="Au-delà des {{ $scan->visibleCeiling() }} résultats que Shodan laisse consulter">↑</span>
                            @endif
                        </td>
                        <td class="num">{{ round($scan->coverage() * 100) }} %</td>
                        <td class="num">{{ $scan->requests_used }} / {{ $scan->max_requests }}</td>
                        <td><span class="badge badge-{{ $scan->status }}">{{ $scan->state_label }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{ $scans->links() }}

    <p class="footnote">
        La <strong>couverture</strong> est le rapport entre ce qu'on a réellement
        en base et ce que Shodan annonce. Elle atteint rarement 100 % : les
        classements ne listent pas toutes les valeurs, il reste toujours une queue
        de distribution invisible. La flèche ↑ signale les scans qui ont dépassé
        les {{ config('geoscan.enumeration.page_limit') * config('geoscan.enumeration.per_page') }}
        résultats consultables sans abonnement.
    </p>
@endsection
