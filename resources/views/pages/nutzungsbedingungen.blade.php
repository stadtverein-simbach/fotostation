@extends('layouts.app')

@section('title', 'Nutzungsbedingungen')

@section('content')
    <article class="space-y-4 text-stone-800">
        <h1 class="text-2xl font-semibold">Nutzungsbedingungen der Fotostation</h1>

        {{-- Entwurf, bitte vor dem Start prüfen lassen. --}}
        <p>Mit dem Hochladen bestätigst du, dass du das Foto selbst aufgenommen hast.</p>
        <p>
            Du stellst das Foto unter <a class="underline" href="https://creativecommons.org/publicdomain/zero/1.0/deed.de">CC0 1.0</a>
            zur Verfügung. Damit darf es jeder ohne Bedingungen nutzen, auch der {{ config('fotostation.operator.name') }}
            für Bilderreihen, Zeitraffer und Veröffentlichungen. Die fertigen Bilderreihen veröffentlicht der Verein
            unter CC BY 4.0.
        </p>
        <p>Bitte fotografiere keine Personen und keine Autokennzeichen. Erkennbare Gesichter und Kennzeichen machen wir vor der Veröffentlichung unkenntlich.</p>
        <p>Wir prüfen jedes Foto vor der Veröffentlichung. Es besteht kein Anspruch auf Veröffentlichung. Fotos, die nicht von der Station stammen oder gegen Rechte verstoßen, löschen wir.</p>
        <p>Wenn du jünger als 16 Jahre bist, lade bitte nur mit Einverständnis deiner Eltern hoch.</p>
        <p>Über den Link „Melden“ unter jedem Foto kannst du uns auf Probleme hinweisen.</p>
    </article>
@endsection
