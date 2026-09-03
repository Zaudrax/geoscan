@extends('layouts.app')
@section('title', 'Veilles — GeoScan')

@section('content')
    <h1>Veilles</h1>
    <p class="lede">
        Une veille rejoue la même recherche à intervalle régulier et signale ce
        qui est apparu depuis la fois précédente.
        <a href="{{ route('watches.create') }}">Nouvelle veille</a>
    </p>

    @forelse ($watches as $watch)
        <div class="card">
            <h2><a href="{{ route('watches.show', $watch) }}">{{ $watch->label }}</a></h2>
            <p class="dim small mono">{{ $watch->shodanQuery() }}</p>
            <p class="small">
                {{ $watch->country_name }} — toutes les {{ $watch->interval_hours }} h —
                {{ $watch->scans->count() ? 'dernier passage '.$watch->last_run_at?->diffForHumans() : 'jamais exécutée' }}
                @if (! $watch->is_active)
                    <span class="chip level-info">suspendue</span>
                @endif
            </p>
        </div>
    @empty
        <div class="card"><p class="empty">Aucune veille enregistrée.</p></div>
    @endforelse
@endsection
