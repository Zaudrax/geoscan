@extends('layouts.app')
@section('title', 'Nouveau scan — GeoScan')

@section('content')
    <h1>Nouveau scan par pays</h1>
    <p class="lede">
        Énumère les machines d'un pays observées à un instant précis <strong>en
        GMT</strong> — c'est le fuseau de l'en-tête <span class="mono">Date:</span>
        d'une bannière HTTP, celui que Shodan indexe —, en découpant
        la recherche en tranches assez étroites pour rester sous le plafond des
        {{ config('geoscan.enumeration.page_limit') * config('geoscan.enumeration.per_page') }}
        résultats consultables.
    </p>

    @unless ($isAuthenticated)
        <div class="alert alert-error">
            <strong>Aucun compte Shodan configuré.</strong>
            Les filtres <span class="mono">country:</span> et <span class="mono">port:</span>
            dont dépend l'énumération sont refusés aux visiteurs anonymes.
            Dans <span class="mono">.env</span>, mets
            <span class="mono">SHODAN_LOGIN_ENABLED=true</span> puis, au choix :
            <ul>
                <li>
                    <span class="mono">SHODAN_SESSION_COOKIE</span> — l'en-tête
                    <span class="mono">Cookie</span> recopié depuis un navigateur déjà
                    connecté. La seule voie possible pour un compte créé avec
                    « Se connecter avec Google » : il n'a pas de mot de passe.
                </li>
                <li>
                    <span class="mono">SHODAN_EMAIL</span> +
                    <span class="mono">SHODAN_PASSWORD</span> — pour un compte qui a
                    réellement un mot de passe.
                </li>
            </ul>
            Vérifie ensuite avec <span class="mono">php artisan geoscan:session</span>.
        </div>
    @endunless

    <div class="card">
        <form method="POST" action="{{ route('scans.store') }}">
            @csrf

            <div class="form-row">
                <div class="form-cell grow">
                    <label class="field" for="country_code">Pays</label>
                    <select id="country_code" name="country_code" required>
                        @foreach ($countries as $code => $name)
                            <option value="{{ $code }}" @selected(old('country_code', 'PL') === $code)>
                                {{ $name }} ({{ $code }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="form-cell">
                    <label class="field" for="observed_on">Date</label>
                    <input type="date" id="observed_on" name="observed_on"
                           value="{{ old('observed_on', now()->toDateString()) }}">
                </div>

                <div class="form-cell narrow">
                    <label class="field" for="observed_hour">Heure <span class="dim">GMT</span></label>
                    <input type="number" id="observed_hour" name="observed_hour" min="0" max="23"
                           value="{{ old('observed_hour', now()->subMinutes(10)->format('G')) }}">
                </div>

                <div class="form-cell narrow">
                    <label class="field" for="observed_minute">Minute</label>
                    <input type="number" id="observed_minute" name="observed_minute" min="0" max="59"
                           value="{{ old('observed_minute', now()->subMinutes(10)->format('i')) }}">
                </div>

                <div class="form-cell narrow">
                    <label class="field" for="observed_second">Seconde</label>
                    <input type="number" id="observed_second" name="observed_second" min="0" max="59"
                           value="{{ old('observed_second') }}" placeholder="—">
                </div>
            </div>

            <p class="dim" style="text-align:center;margin:.5rem 0">— ou, au lieu d'un instant —</p>

            <div class="form-row">
                <div class="form-cell grow">
                    <label class="field" for="base_term">Terme de bannière <span class="dim">(ex. Server: yawcam)</span></label>
                    <input type="text" id="base_term" name="base_term" maxlength="255"
                           value="{{ old('base_term') }}" placeholder="Server: yawcam">
                </div>
            </div>

            <button type="submit">Lancer le scan</button>
        </form>
    </div>

    <div class="alert alert-info">
        <strong>La seconde compte.</strong>
        Renseignée, elle donne une requête unique, généralement quelques dizaines
        de résultats — le cas idéal. Laissée vide, les 60 secondes de la minute
        deviennent la première dimension de découpage : c'est la partition la
        plus propre qui soit, mais elle coûte une requête par seconde et le
        budget de <strong>{{ $maxRequests }} requêtes</strong> s'épuisera avant la fin.
    </div>

    <div class="alert alert-info">
        <strong>Scan par terme.</strong>
        Renseigne un terme de bannière (ex. <span class="mono">Server: yawcam</span>)
        et le moteur découpe cette recherche par facettes au lieu d'un horodatage.
        C'est la façon d'aller au-delà des 20 résultats visibles d'un pool comme
        les 81 webcams yawcam d'un pays, sans compte payant. La date et l'heure
        sont alors ignorées.
    </div>

    <div class="card">
        <h2>Comment le plafond est contourné</h2>
        <p class="explain">
            Shodan annonce toujours le nombre total de résultats, mais n'en laisse
            consulter que
            {{ config('geoscan.enumeration.page_limit') * config('geoscan.enumeration.per_page') }}.
            Les classements affichés à gauche de ses pages sont en revanche calculés
            sur la totalité, et chacun est un lien vers la même requête plus un
            filtre : ils décrivent gratuitement une partition du jeu de résultats.
        </p>
<pre class="tree">country:"PL" Date: Tue, 01 Sep 2026 09:13:03 GMT   39  <span class="split">trop</span>
├── port:80                                        22  <span class="split">trop</span>
│   ├── org:"Multinet24 Sp.zoo"                     9  <span class="ok">moissonné</span>
│   └── org:"Oxylion Sp. z o.o."                    7  <span class="ok">moissonné</span>
├── port:8080                                       4  <span class="ok">moissonné</span>
├── port:443                                        3  <span class="ok">moissonné</span>
└── port:8443                                       2  <span class="ok">moissonné</span></pre>
    </div>

    @if ($scans->isNotEmpty())
        <div class="card">
            <h2>Derniers scans</h2>
            <table>
                <thead>
                <tr><th>Pays</th><th>Instant visé</th><th class="num">Hôtes</th><th>Statut</th></tr>
                </thead>
                <tbody>
                @foreach ($scans as $scan)
                    <tr>
                        <td><a href="{{ route('scans.show', $scan) }}">{{ $scan->country_name }}</a></td>
                        <td class="mono">{{ $scan->windowLabel() }}</td>
                        <td class="num">{{ $scan->unique_hosts }}</td>
                        <td><span class="badge badge-{{ $scan->status }}">{{ $scan->state_label }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p class="footnote">
        Un scan envoie jusqu'à {{ $maxRequests }} requêtes vers shodan.io, espacées
        de {{ config('geoscan.request_delay') }} s : compte plusieurs minutes. Le
        travail se fait en file d'attente, la page du scan suit l'avancement.
    </p>
@endsection
