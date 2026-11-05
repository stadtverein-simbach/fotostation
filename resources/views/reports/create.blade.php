@extends('layouts.app')

@section('title', 'Foto melden')
@section('robots', 'noindex')

@section('content')
    <h1 class="text-2xl font-semibold">Foto melden</h1>
    <p class="mt-2 text-stone-700">
        Bist du auf diesem Foto zu erkennen oder verstößt es gegen Rechte? Sag uns kurz, worum es geht.
        Wir prüfen jede Meldung und entfernen das Foto bei Bedarf.
    </p>

    <img src="{{ $submission->thumbUrl() }}" alt="Gemeldetes Foto" class="mt-4 max-h-56 rounded-lg">

    @if ($errors->any())
        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-900">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ route('reports.store', $submission) }}" class="mt-6 space-y-4 rounded-xl border border-stone-200 bg-white p-5">
        @csrf
        <x-honeypot />

        <div>
            <label for="reason" class="block font-medium">Grund</label>
            <textarea id="reason" name="reason" rows="4" required minlength="10" maxlength="2000"
                      class="mt-2 block w-full rounded-lg border border-stone-300 px-3 py-2">{{ old('reason') }}</textarea>
        </div>

        <div>
            <label for="contact" class="block font-medium">Kontakt <span class="font-normal text-stone-500">(optional, für Rückfragen)</span></label>
            <input id="contact" name="contact" type="text" maxlength="255" value="{{ old('contact') }}"
                   class="mt-2 block w-full rounded-lg border border-stone-300 px-3 py-2">
        </div>

        <button type="submit" class="rounded-lg bg-stone-900 px-4 py-3 font-medium text-white">Meldung absenden</button>
    </form>
@endsection
