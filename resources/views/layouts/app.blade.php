<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'GeoScan')</title>
    {{-- Our own pages must never end up indexed: they list third party IPs. --}}
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @yield('head')
</head>
<body>
<header class="topbar">
    <div class="wrap">
        <div class="brand">Geo<span>Scan</span></div>
        <nav>
            <a href="{{ route('scans.create') }}" class="{{ request()->routeIs('scans.create') ? 'active' : '' }}">Nouveau scan</a>
            <a href="{{ route('scans.index') }}" class="{{ request()->routeIs('scans.index', 'scans.show') ? 'active' : '' }}">Scans</a>
            <a href="{{ route('searches.create') }}" class="{{ request()->routeIs('searches.create') ? 'active' : '' }}">Nouvelle recherche</a>
            <a href="{{ route('searches.index') }}" class="{{ request()->routeIs('searches.index', 'searches.show') ? 'active' : '' }}">Historique</a>
            <a href="{{ route('watches.index') }}" class="{{ request()->routeIs('watches.*') ? 'active' : '' }}">Veilles</a>
            <a href="{{ route('journal.index') }}" class="{{ request()->routeIs('journal.*') ? 'active' : '' }}">Journal</a>
            <a href="{{ route('hosts.create') }}" class="{{ request()->routeIs('hosts.*') ? 'active' : '' }}">Fiches hôte</a>
        </nav>
    </div>
</header>

<main class="wrap @yield('wrap-class')">
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error">
            @foreach ($errors->all() as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    @yield('content')
</main>
</body>
</html>
