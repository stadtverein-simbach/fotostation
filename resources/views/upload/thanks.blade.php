@extends('layouts.app')

@section('title', 'Danke')
@section('robots', 'noindex')

@section('content')
    <h1 class="text-2xl font-semibold">Danke für dein Foto!</h1>
    <p class="mt-3 text-stone-700">
        Wir prüfen es in den nächsten Tagen.
        @if (config('fotostation.display.timelapse'))
            Nach der Freigabe erscheint es in der Galerie
            und in der nächsten Bilderreihe, die jede Nacht neu entsteht.
        @else
            Nach der Freigabe erscheint es in der Galerie.
        @endif
    </p>

    @if ($series?->videoUrl())
        <h2 class="mt-8 text-lg font-semibold">So hat sich der Blick bisher verändert</h2>
        <video class="mt-3 w-full rounded-xl" src="{{ $series->videoUrl() }}" controls playsinline muted loop></video>
    @endif

    <a href="{{ route('stations.show', $station) }}" class="mt-8 inline-block rounded-lg bg-stone-900 px-4 py-3 font-medium text-white">
        Zur Galerie
    </a>
@endsection
