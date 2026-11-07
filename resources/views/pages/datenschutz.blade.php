@extends('layouts.app')

@section('title', 'Datenschutz')

@section('content')
    <article class="space-y-4 text-stone-800">
        <h1 class="text-2xl font-semibold">Datenschutzerklärung der Fotostation</h1>

        {{-- Entwurf auf Basis der technischen Umsetzung. Vor dem Start prüfen und um die Angaben des Vereins ergänzen. --}}

        <h2 class="text-lg font-semibold">Verantwortlicher</h2>
        <p>{{ config('fotostation.operator.name') }}, siehe <a class="underline" href="{{ route('pages.impressum') }}">Impressum</a>.</p>

        <h2 class="text-lg font-semibold">Was wir beim Hochladen verarbeiten</h2>
        <ul class="list-disc pl-5">
            <li>Das Foto. Vor dem Speichern entfernen wir alle Metadaten (z. B. GPS-Position und Gerätedaten). Nur das Aufnahmedatum übernehmen wir, um die Bilderreihe zu sortieren.</li>
            <li>Optional einen von dir gewählten Namen für die Galerie.</li>
            <li>Zeitpunkt und Version deiner Zustimmung zu den Nutzungsbedingungen.</li>
            <li>Einen Hashwert deiner IP-Adresse zum Schutz vor Missbrauch. Die IP-Adresse selbst speichern wir nicht. Der Hashwert wird nach höchstens 72 Stunden gelöscht.</li>
        </ul>
        <p>Rechtsgrundlagen: Art. 6 Abs. 1 lit. a und b DSGVO (Einwilligung und Nutzungsbedingungen) für das Foto, Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse an Missbrauchsschutz) für den IP-Hashwert und die Spamprüfung.</p>

        <h2 class="text-lg font-semibold">Spamschutz</h2>
        <p>Zum Schutz vor automatisierten Uploads nutzen wir ALTCHA. Dabei löst dein Browser eine kleine Rechenaufgabe, die wir auf unserem eigenen Server prüfen. Es werden keine Daten an Dritte übermittelt, kein Cookie gesetzt und keine personenbezogenen Daten gespeichert.</p>

        <h2 class="text-lg font-semibold">Hosting und Empfänger</h2>
        <ul class="list-disc pl-5">
            <li>Anwendung und Datenbank: Laravel Cloud, Region EU (Frankfurt). {{-- TODO: Anbieter, AVV und Übermittlungsgrundlage ergänzen. --}}</li>
            <li>Dateispeicher: Laravel Object Storage (Cloudflare R2), EU-Jurisdiktion. {{-- TODO: AVV und Übermittlungsgrundlage ergänzen. --}}</li>
            <li>Bildverarbeitung: eigener Server bei … in Deutschland. Er erhält die Fotos nur kurzzeitig zur Verarbeitung und speichert sie nicht.</li>
        </ul>

        <h2 class="text-lg font-semibold">Speicherdauer</h2>
        <p>Abgelehnte Fotos löschen wir sofort, automatisch aussortierte nach spätestens 7 Tagen. Veröffentlichte Fotos bleiben für die Dauer des Projekts oder bis zu einer berechtigten Löschanfrage gespeichert. @if (config('fotostation.keep_originals'))
            Die unbearbeitete Fassung (ohne Metadaten, aber ohne Verpixelung) bewahren wir nach der Veröffentlichung in einem nicht öffentlichen Speicher auf. Sie wird zusammen mit dem veröffentlichten Foto gelöscht. Öffentlich ist nur die bearbeitete Fassung.
            {{-- TODO: Zweck (z. B. spätere Neubearbeitung) und Rechtsgrundlage für das Aufbewahren der Originale ergänzen und prüfen lassen. --}}
        @else
            Die Originale löschen wir nach der Veröffentlichung, öffentlich bleibt nur die bearbeitete Fassung.
        @endif</p>

        <h2 class="text-lg font-semibold">Cookies</h2>
        <p>Wir verwenden nur technisch notwendige Cookies (Sitzung, Schutz vor Formularmissbrauch).</p>

        <h2 class="text-lg font-semibold">Deine Rechte</h2>
        <p>Du hast das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Widerspruch und Beschwerde bei einer Aufsichtsbehörde, in Bayern dem Landesamt für Datenschutzaufsicht. Zum Entfernen eines Fotos nutze den Link „Melden“ oder schreib uns.</p>
    </article>
@endsection
