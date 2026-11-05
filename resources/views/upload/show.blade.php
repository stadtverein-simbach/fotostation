@extends('layouts.app')

@section('title', $station->name)
@section('robots', 'noindex')

@section('content')
    <h1 class="text-2xl font-semibold">{{ $station->name }}</h1>

    @if ($station->description)
        <p class="mt-2 text-stone-700">{{ $station->description }}</p>
    @endif

    @if (! $ready)
        <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
            Diese Station nimmt gerade noch keine Fotos an. Schau bald wieder vorbei.
        </div>
    @else
        <ol class="mt-6 list-decimal space-y-1 pl-5 text-stone-700">
            <li>Handy in den Rahmen legen, Hauptkamera nach vorn.</li>
            <li>Foto ohne Zoom aufnehmen, bitte keine Personen im Bild.</li>
            <li>Hier hochladen. Wir prüfen jedes Foto, bevor es erscheint.</li>
        </ol>

        @if ($errors->any())
            <div class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-900">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('upload.store', $station) }}" enctype="multipart/form-data"
              class="mt-6 space-y-5 rounded-xl border border-stone-200 bg-white p-5" data-upload-form>
            @csrf
            <x-honeypot />

            <div>
                <label for="photo" class="block font-medium">Foto</label>
                <input id="photo" name="photo" type="file" required
                       accept="image/jpeg,image/png,image/webp" capture="environment"
                       class="mt-2 block w-full text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-stone-900 file:px-4 file:py-2 file:text-white">
                <img data-preview alt="Vorschau" class="mt-3 hidden max-h-64 rounded-lg">
            </div>

            <div>
                <label for="name" class="block font-medium">Name <span class="font-normal text-stone-500">(optional)</span></label>
                <input id="name" name="name" type="text" maxlength="40" value="{{ old('name') }}"
                       placeholder="Wie sollen wir dich in der Galerie nennen?"
                       class="mt-2 block w-full rounded-lg border border-stone-300 px-3 py-2">
                <p class="mt-1 text-sm text-stone-500">Leer lassen, um anonym zu bleiben.</p>
            </div>

            <label class="flex gap-3">
                <input type="checkbox" name="consent" value="1" required @checked(old('consent')) class="mt-1 h-5 w-5">
                <span class="text-sm text-stone-700">{{ $consent->text }}
                    <a class="underline" href="{{ route('pages.terms') }}" target="_blank">Nutzungsbedingungen</a>,
                    <a class="underline" href="{{ route('pages.datenschutz') }}" target="_blank">Datenschutz</a>.
                </span>
            </label>

            @if (config('fotostation.captcha.enabled'))
                <div class="frc-captcha" data-sitekey="{{ config('fotostation.captcha.sitekey') }}"
                     data-endpoint="{{ config('fotostation.captcha.endpoint') }}" data-lang="de"></div>
            @endif

            <button type="submit" class="w-full rounded-lg bg-stone-900 px-4 py-3 font-medium text-white disabled:opacity-60">
                Foto hochladen
            </button>
        </form>
    @endif
@endsection
