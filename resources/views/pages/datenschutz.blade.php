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
            <li>Das Foto. Vor dem Speichern entfernen wir alle Metadaten aus der Bilddatei, die Bilddaten selbst bleiben unverändert. Einige davon übernehmen wir vorher getrennt in unsere Datenbank:
                <ul class="list-[circle] pl-5">
                    <li>das Aufnahmedatum, um die Bilderreihe zu sortieren,</li>
                    <li>Hersteller und Modell des Geräts sowie Kameraeinstellungen (Brennweite, Zoom, Belichtung, Blitz) und die Bearbeitungssoftware, um bei der Prüfung zu erkennen, warum ein Foto nicht zum Rahmen passt oder ob es nachträglich bearbeitet wurde.</li>
                </ul>
                Seriennummern, Namen oder andere Kennungen aus den Bilddaten übernehmen wir nicht. Enthält das Foto eine GPS-Position, berechnen wir daraus nur den ungefähren Abstand zur Fotostation (auf 10 Meter gerundet, höchstens {{ number_format(config('fotostation.upload.gps_max_distance') / 1000, 0, ',', '.') }} km) und speichern ausschließlich diesen Wert, nicht die Position selbst. Er hilft uns, Fotos zu erkennen, die nicht an der Station aufgenommen wurden.</li>
            <li>Optional einen von dir gewählten Namen für die Galerie.</li>
            <li>Zeitpunkt und Version deiner Zustimmung zu den Nutzungsbedingungen.</li>
            <li>Einen Hashwert deiner IP-Adresse zum Schutz vor Missbrauch. Die IP-Adresse selbst speichern wir nicht. Der Hashwert wird nach höchstens 72 Stunden gelöscht.</li>
        </ul>
        <p>Rechtsgrundlagen: Art. 6 Abs. 1 lit. a und b DSGVO (Einwilligung und Nutzungsbedingungen) für das Foto (zum Aufbewahren des Originals siehe „Speicherdauer“), Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse an Missbrauchsschutz) für den IP-Hashwert, die Spamprüfung und die übernommenen Kameraangaben.</p>

        <h2 class="text-lg font-semibold">Spamschutz</h2>
        <p>Zum Schutz vor automatisierten Uploads nutzen wir ALTCHA. Dabei löst dein Browser eine kleine Rechenaufgabe, die wir auf unserem eigenen Server prüfen. Es werden keine Daten an Dritte übermittelt, kein Cookie gesetzt und keine personenbezogenen Daten gespeichert.</p>

        <h2 class="text-lg font-semibold">Hosting und Empfänger</h2>
        <ul class="list-disc pl-5">
            <li>Anwendung und Datenbank: Laravel Cloud, Region EU (Frankfurt). {{-- TODO: Anbieter, AVV und Übermittlungsgrundlage ergänzen. --}}</li>
            <li>Dateispeicher: Laravel Object Storage (Cloudflare R2), EU-Jurisdiktion. {{-- TODO: AVV und Übermittlungsgrundlage ergänzen. --}}</li>
            <li>Bildverarbeitung: eigener Server bei … in Deutschland. Er erhält die Fotos nur kurzzeitig zur Verarbeitung und speichert sie nicht.</li>
        </ul>

        <h2 class="text-lg font-semibold">Speicherdauer</h2>
        <p>Abgelehnte Fotos löschen wir sofort, automatisch aussortierte nach spätestens 7 Tagen. Veröffentlichte Fotos bleiben für die Dauer des Projekts oder bis zu einer berechtigten Löschanfrage gespeichert.
            Das Originalfoto in voller Qualität (Bilddaten unverändert, aber ohne Metadaten und ohne Verpixelung) bewahren wir in einem nicht öffentlichen Speicher auf, solange das Foto bei uns gespeichert ist. Es wird zusammen mit dem Foto gelöscht. Öffentlich ist nur die bearbeitete Fassung.
        </p>
        <p>
            Zweck: Wir brauchen das Original, um das Foto später neu bearbeiten zu können, ohne an Qualität zu verlieren, zum Beispiel wenn sich Ausrichtung, Verpixelung oder Zeitraffer technisch verbessern lassen oder eine Bilderreihe neu erstellt wird. Dabei verpixeln wir Personen in jeder neu veröffentlichten Fassung wieder. Zugriff auf die Originale haben nur die für die Moderation zuständigen Personen des Vereins.
            Rechtsgrundlage ist für die Person, die das Foto hochlädt, ihre Zustimmung beim Hochladen (Art. 6 Abs. 1 lit. a und b DSGVO), für weitere auf dem Foto erkennbare Personen unser berechtigtes Interesse an einer dauerhaft gleichbleibenden Qualität der Bilderreihe (Art. 6 Abs. 1 lit. f DSGVO). Du kannst jederzeit verlangen, dass ein Foto samt Original gelöscht wird, auch wenn du nur darauf zu sehen bist.
            {{-- TODO: vor dem Start prüfen lassen (Datenschutzbeauftragte/r bzw. fachkundige Person). --}}
        </p>

        <h2 class="text-lg font-semibold">Cookies</h2>
        <p>Wir verwenden nur technisch notwendige Cookies (Sitzung, Schutz vor Formularmissbrauch).</p>

        <h2 class="text-lg font-semibold">Deine Rechte</h2>
        <p>Du hast das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Widerspruch und Beschwerde bei einer Aufsichtsbehörde, in Bayern dem Landesamt für Datenschutzaufsicht. Zum Entfernen eines Fotos nutze den Link „Melden“ oder schreib uns.</p>
    </article>
@endsection
