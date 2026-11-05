@extends('layouts.app')

@section('title', 'Stationen')

@section('content')
    <h1 class="text-2xl font-semibold">Fotostationen</h1>

    <ul class="mt-6 grid gap-4 sm:grid-cols-2">
        @forelse ($stations as $station)
            <li class="rounded-xl border border-stone-200 bg-white p-4">
                <a href="{{ route('stations.show', $station) }}" class="text-lg font-medium underline">{{ $station->name }}</a>
                <p class="mt-1 text-sm text-stone-500">{{ $station->published_submissions_count }} Fotos</p>
            </li>
        @empty
            <li class="text-stone-500">Noch keine Station eingerichtet.</li>
        @endforelse
    </ul>
@endsection
