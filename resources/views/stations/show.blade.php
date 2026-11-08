@extends('layouts.app')

@section('title', $station->name)

@section('content')
    <h1 class="text-2xl font-semibold">{{ $station->name }}</h1>
    @if ($station->description)
        <p class="mt-2 text-stone-700">{{ $station->description }}</p>
    @endif

    @if ($series?->videoUrl())
        <section class="mt-8">
            <h2 class="text-lg font-semibold">Zeitraffer</h2>
            <video class="mt-3 w-full rounded-xl" src="{{ $series->videoUrl() }}" controls playsinline muted loop></video>
            <p class="mt-2 text-sm text-stone-500">
                {{ $series->frame_count }} Fotos
                @if ($series->period_from && $series->period_to)
                    vom {{ $series->period_from->format('d.m.Y') }} bis {{ $series->period_to->format('d.m.Y') }}
                @endif
                · {{ config('fotostation.operator.series_credit') }}
                · Lizenz <a class="underline" href="https://creativecommons.org/licenses/by/4.0/deed.de" rel="license">CC BY 4.0</a>
            </p>
        </section>
    @endif

    @if ($first && $last && $first->isNot($last))
        <section class="mt-8">
            <h2 class="text-lg font-semibold">Vorher und nachher</h2>
            <div class="relative mt-3 overflow-hidden rounded-xl">
                <img src="{{ $last->publicUrl() }}" alt="Neuestes Foto" class="block w-full">
                <img src="{{ $first->publicUrl() }}" alt="Ältestes Foto" data-before
                     class="absolute inset-0 h-full w-full object-cover" style="clip-path: inset(0 50% 0 0)">
                <input type="range" min="0" max="100" value="50" aria-label="Vorher und nachher vergleichen"
                       class="absolute inset-x-0 bottom-3 mx-auto w-2/3"
                       oninput="this.parentElement.querySelector('[data-before]').style.clipPath = 'inset(0 ' + (100 - this.value) + '% 0 0)'">
            </div>
            <p class="mt-2 text-sm text-stone-500">
                {{ ($first->taken_at ?? $first->created_at)->format('d.m.Y') }}
                bis {{ ($last->taken_at ?? $last->created_at)->format('d.m.Y') }}
            </p>
        </section>
    @endif

    <section class="mt-8">
        <h2 class="text-lg font-semibold">Alle Fotos</h2>
        <ul class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
            @forelse ($photos as $photo)
                <li class="overflow-hidden rounded-lg border border-stone-200 bg-white">
                    <a href="{{ $photo->publicUrl() }}" target="_blank" data-lightbox
                       data-caption="{{ ($photo->taken_at ?? $photo->created_at)->format('d.m.Y') }}@if ($photo->display_name) · {{ $photo->display_name }}@endif">
                        <img src="{{ $photo->thumbUrl() }}" alt="Foto vom {{ ($photo->taken_at ?? $photo->created_at)->format('d.m.Y') }}" loading="lazy" class="aspect-[4/3] w-full object-cover">
                    </a>
                    <div class="flex items-center justify-between px-2 py-1 text-xs text-stone-500">
                        <span>{{ ($photo->taken_at ?? $photo->created_at)->format('d.m.Y') }}@if ($photo->display_name) · {{ $photo->display_name }}@endif</span>
                        <a class="underline" href="{{ route('reports.create', $photo) }}">Melden</a>
                    </div>
                </li>
            @empty
                <li class="col-span-full text-stone-500">Noch keine Fotos freigegeben.</li>
            @endforelse
        </ul>

        <div class="mt-4">{{ $photos->links() }}</div>
    </section>

    {{-- Lightbox für die Fotoübersicht, gesteuert über resources/js/lightbox.js --}}
    <dialog data-lightbox-dialog aria-label="Fotoansicht"
            class="m-0 h-full max-h-none w-full max-w-none bg-transparent p-0 backdrop:bg-black/90">
        <div class="flex h-full w-full flex-col items-center justify-center p-4 text-white">
            <figure class="flex flex-col items-center">
                <img data-lightbox-image alt="" class="max-h-[calc(100dvh-6rem)] max-w-full object-contain select-none">
                <figcaption data-lightbox-caption class="mt-3 text-center text-sm text-stone-300"></figcaption>
            </figure>
            <p data-lightbox-counter class="mt-1 text-xs text-stone-400"></p>
        </div>
        <button type="button" data-lightbox-close aria-label="Schließen"
                class="absolute top-3 right-3 rounded-full bg-black/50 px-3 py-1 text-2xl text-white hover:bg-black/80">&times;</button>
        <button type="button" data-lightbox-prev aria-label="Vorheriges Foto"
                class="absolute top-1/2 left-3 -translate-y-1/2 rounded-full bg-black/50 px-3 py-2 text-3xl text-white hover:bg-black/80">&lsaquo;</button>
        <button type="button" data-lightbox-next aria-label="Nächstes Foto"
                class="absolute top-1/2 right-3 -translate-y-1/2 rounded-full bg-black/50 px-3 py-2 text-3xl text-white hover:bg-black/80">&rsaquo;</button>
    </dialog>

    <p class="mt-8 text-sm text-stone-500">
        Einzelfotos: <a class="underline" href="https://creativecommons.org/publicdomain/zero/1.0/deed.de">CC0 1.0</a>.
        Mitmachen kannst du direkt an der Station über den QR-Code.
    </p>
@endsection
