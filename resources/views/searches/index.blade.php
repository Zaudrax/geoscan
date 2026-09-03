@extends('layouts.app')
@section('title', 'Historique des recherches — GeoScan')

@section('content')
    <h1>Historique des recherches</h1>
    <p class="lede">Les recherches déjà archivées, de la plus récente à la plus ancienne.</p>

    <div class="card">
        @if ($searches->isEmpty())
            <p class="empty">
                Aucune recherche archivée pour l'instant —
                <a href="{{ route('searches.create') }}">lances-en une</a>.
            </p>
        @else
            <table>
                <thead>
                <tr>
                    <th>Requête</th>
                    <th>Scrapée le</th>
                    <th class="num">Total résultats</th>
                    <th class="num">Classements</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($searches as $search)
                    <tr>
                        <td><a class="mono" href="{{ route('searches.show', $search) }}">{{ $search->query }}</a></td>
                        <td>{{ $search->scraped_at->format('d/m/Y H:i:s') }}</td>
                        <td class="num">{{ number_format($search->total_results, 0, ',', ' ') }}</td>
                        <td class="num">{{ $search->facets()->count() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{ $searches->links() }}

    <p class="footnote">
        Ouvrir une entrée réaffiche les classements <strong>tels qu'ils ont été
        enregistrés</strong>. Consulter une archive ne re-scrape jamais Shodan :
        pour des chiffres à jour, il faut relancer explicitement une recherche.
    </p>
@endsection
