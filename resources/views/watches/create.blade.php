@extends('layouts.app')
@section('title', 'Nouvelle veille — GeoScan')

@section('content')
    <h1>Nouvelle veille</h1>
    <p class="lede">
        Enregistrer une veille ne déclenche aucune requête : le premier scan
        partira au prochain passage du planificateur.
    </p>

    <div class="card" style="max-width:560px">
        <form method="POST" action="{{ route('watches.store') }}">
            @csrf

            <label for="label">Intitulé</label>
            <input type="text" id="label" name="label" value="{{ old('label') }}" required
                   placeholder="Webcams yawcam en Suède">

            <label for="country_code" style="margin-top:14px">Pays</label>
            <select id="country_code" name="country_code" required>
                @foreach ($countries as $code => $name)
                    <option value="{{ $code }}" @selected(old('country_code') === $code)>{{ $name }}</option>
                @endforeach
            </select>

            <label for="base_term" style="margin-top:14px">Terme cherché dans la bannière</label>
            <input type="text" id="base_term" name="base_term" value="{{ old('base_term') }}" required
                   placeholder='"Server: yawcam"'>

            <label for="interval_hours" style="margin-top:14px">Intervalle (heures)</label>
            <input type="number" id="interval_hours" name="interval_hours"
                   value="{{ old('interval_hours', 24) }}" min="1" max="720" required>

            <button type="submit" style="margin-top:18px">Enregistrer la veille</button>
        </form>
    </div>
@endsection
