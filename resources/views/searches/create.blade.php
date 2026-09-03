@extends('layouts.app')
@section('title', 'Nouvelle recherche — GeoScan')

@section('content')
    <h1>Nouvelle recherche</h1>
    <p class="lede">
        Lance un scraping de la page de résultats publique de shodan.io et archive
        le total ainsi que les cinq classements « Top ».
    </p>

    <div class="card">
        <form method="POST" action="{{ route('searches.store') }}" data-scraping-form>
            @csrf
            <label class="field" for="query">Requête Shodan</label>
            <input type="text" id="query" name="query" value="{{ old('query') }}"
                   placeholder="nginx" autocomplete="off" autofocus required>
            <button type="submit">Scraper et archiver</button>
            {{-- Scraping can take about thirty seconds: the courtesy delay adds
                 to Shodan's own response time. With no visual feedback the user
                 assumes the page has frozen. --}}
            <p class="pending" hidden>
                Scraping en cours… Le client attend jusqu'à
                {{ config('geoscan.request_delay') }} s avant d'émettre (délai de
                politesse), puis interroge shodan.io. Compte une trentaine de
                secondes au maximum.
            </p>
        </form>
    </div>

    <div class="alert alert-info">
        En visiteur anonyme, Shodan refuse les <strong>filtres</strong> de recherche
        (<span class="mono">country:</span>, <span class="mono">port:</span>,
        <span class="mono">org:</span>…) : ils demandent un compte connecté.
        Utilise une requête simple comme <span class="mono">nginx</span>,
        <span class="mono">apache</span> ou <span class="mono">webcam</span>.
    </div>

    <script>
        // Visual feedback during the wait, and a guard against the double click
        // that would send two requests to shodan.io for nothing.
        document.querySelector('[data-scraping-form]')?.addEventListener('submit', (event) => {
            const button = event.currentTarget.querySelector('button');
            button.disabled = true;
            button.textContent = 'Scraping en cours…';
            event.currentTarget.querySelector('.pending').hidden = false;
        });
    </script>

    <p class="footnote">
        Chaque soumission envoie une requête réelle vers shodan.io, avec un
        User-Agent identifiable et un délai minimum de
        {{ config('geoscan.request_delay') }} s entre deux appels
        (« Crawl-delay: 10 » du robots.txt).
    </p>
@endsection
