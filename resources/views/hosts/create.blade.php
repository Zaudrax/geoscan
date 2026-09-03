@extends('layouts.app')
@section('title', 'Fiches hote — GeoScan')

@section('content')
    <h1>Consulter une fiche hôte</h1>
    <p class="lede">
        Chaque consultation tente une récupération fraîche de la fiche, puis
        conserve un instantané daté. Les instantanés précédents ne sont jamais
        écrasés : ils forment la ligne du temps de l'hôte.
    </p>

    <div class="card">
        <form method="POST" action="{{ route('hosts.store') }}" data-scraping-form>
            @csrf
            <label class="field" for="ip">Adresse IP</label>
            <input type="text" id="ip" name="ip" value="{{ old('ip') }}"
                   placeholder="8.8.8.8" autocomplete="off" autofocus required>
            <button type="submit">Ouvrir la fiche</button>
            <p class="pending" hidden>
                Ouverture de la fiche… Si aucun instantané récent n'existe, la
                page est scrapée : compte une trentaine de secondes au maximum.
            </p>
        </form>

        <script>
            document.querySelector('[data-scraping-form]')?.addEventListener('submit', (event) => {
                const button = event.currentTarget.querySelector('button');
                button.disabled = true;
                button.textContent = 'Chargement…';
                event.currentTarget.querySelector('.pending').hidden = false;
            });
        </script>
    </div>

    @if ($hosts->isNotEmpty())
        <div class="card">
            <h2>Hôtes déjà observés</h2>
            <table>
                <thead>
                <tr>
                    <th>IP</th>
                    <th>Organisation</th>
                    <th>Localisation</th>
                    <th class="num">Instantanés</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($hosts as $host)
                    <tr>
                        <td><a class="mono" href="{{ route('hosts.show', $host->ip) }}">{{ $host->ip }}</a></td>
                        <td>{{ $host->latestSnapshot?->organization ?? '—' }}</td>
                        <td>{{ collect([$host->latestSnapshot?->city, $host->latestSnapshot?->country])->filter()->join(', ') ?: '—' }}</td>
                        <td class="num">{{ $host->snapshots()->count() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p class="footnote">
        Garde-fou actif : si un instantané de cette IP date de moins de
        {{ config('geoscan.host_cooldown') }} s, il est réutilisé au lieu de
        refrapper shodan.io.
    </p>
@endsection
