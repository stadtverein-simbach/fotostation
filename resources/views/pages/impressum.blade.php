@extends('layouts.app')

@section('title', 'Impressum')

@section('content')
    <article class="space-y-4 text-stone-800">
        <h1 class="text-2xl font-semibold">Impressum</h1>

        {{-- TODO: Angaben nach § 5 DDG vervollständigen (Vorstand, Registergericht, Registernummer, Kontakt). --}}
        <p class="mt-4">
            {{ config('fotostation.operator.name') }}<br>
            Anschrift: …<br>
            Vertreten durch den Vorstand: …<br>
            Registergericht: … · Registernummer: …<br>
            E-Mail: …
        </p>
    </article>
@endsection
