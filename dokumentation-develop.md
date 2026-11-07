# SVS Fotostation – Entwicklerdokumentation

> Stand: November 2026 · Bezieht sich auf den Code im Repository `stadtverein-simbach/fotostation`.
> Dieses Dokument wird laufend erweitert. Neue Fragen bitte als eigenen Abschnitt am Ende ergänzen (siehe [Offene Fragen / Erweiterungen](#8-offene-fragen--erweiterungen)).

## Inhalt

1. [Überblick und Architektur](#1-überblick-und-architektur)
2. [Erfassungsmöglichkeiten der Station und ihre Funktionen](#2-erfassungsmöglichkeiten-der-station-und-ihre-funktionen)
3. [Ablauf und Absicherung eines Uploads](#3-ablauf-und-absicherung-eines-uploads)
4. [Optionale Einstellungen (Konfigurationsreferenz)](#4-optionale-einstellungen-konfigurationsreferenz)
5. [Laravel-Befehle (Artisan)](#5-laravel-befehle-artisan)
6. [Was man mit den Bildern machen kann (Galerie, Vorher/Nachher, Zeitraffer)](#6-was-man-mit-den-bildern-machen-kann)
7. [Benötigte Komponenten](#7-benötigte-komponenten)
8. [Offene Fragen / Erweiterungen](#8-offene-fragen--erweiterungen)

---

## 1. Überblick und Architektur

Die Fotostation ist ein fest montierter Rahmen im öffentlichen Raum. Passanten legen ihr Handy in den Rahmen, fotografieren immer denselben Ausschnitt und laden das Foto über einen QR-Code hoch. Aus den freigegebenen Fotos entstehen eine Galerie, ein Vorher/Nachher-Vergleich und ein nächtlich gerenderter Zeitraffer.

```
 Handy (Passant)
   │  GET/POST /s/{station}
   ▼
 ┌──────────────────────────────┐          ┌──────────────────────────┐
 │ Laravel 13 + Filament 5      │  HTTPS   │ cv-worker (optional)     │
 │ (Laravel Cloud)              │ ───────▶ │ FastAPI, OpenCV, ffmpeg  │
 │  - Upload, Validierung       │  Bearer  │ eigener VPS hinter Caddy │
 │  - Queue "uploads"           │  Token   │ speichert nichts         │
 │  - Moderation (/admin)       │          └───────────┬──────────────┘
 │  - Scheduler (nachts)        │                      │ vorsignierte URLs
 └──────┬───────────────┬───────┘                      │ (GET / PUT)
        │               │                              ▼
   PostgreSQL     ┌─────────────────┐  ┌──────────────────┐
                  │ photos-private  │  │ photos-public    │
                  │ (Originale,     │  │ (veröffentlichte │
                  │  Referenzbilder)│  │  Fotos, Videos)  │
                  └─────────────────┘  └──────────────────┘
```

| Baustein | Technik | Aufgabe |
| --- | --- | --- |
| Web-App | Laravel 13, Blade, Tailwind 4, Vite | Upload-Seite, Galerie, Meldeformular, Pflichtseiten |
| Admin | Filament 5 unter `/admin` | Stationen, Moderation, Meldungen, Zustimmungstexte, Dashboard |
| Queue | Laravel Queue (lokal `database`, in Laravel Cloud `cloud`) | Verarbeitungskette pro Einreichung |
| Scheduler | Laravel Scheduler | Nächtliche Aufgaben (Zeitraffer, Löschfristen) |
| Speicher | Zwei S3-kompatible Buckets (lokal MinIO) | privat / öffentlich getrennt |
| cv-worker | Python 3.13, FastAPI, OpenCV 5 (SIFT, YuNet), ffmpeg | Bildabgleich, Ausrichtung, Gesichter, Verpixeln, Zeitraffer |

Die Anwendung hat zwei **Betriebsmodi**, gesteuert über `CV_WORKER_ENABLED`:

| Funktion | Einfacher Modus (`false`, Standard) | Voller Modus (`true`) |
| --- | --- | --- |
| Upload, Neu-Kodieren, Duplikate, Rate-Limit | ja | ja |
| Abgleich mit dem Referenzbild (Spamfilter) | nein – alles geht in die Moderation | ja – passt nicht ⇒ `auto_rejected` |
| Ausrichtung auf die Referenz | nein – nur mittiger Zuschnitt (GD) | ja – Homographie (OpenCV) |
| Gesichtserkennung + automatisches Verpixeln | nein | ja (YuNet) |
| Verpixeln von Hand markierter Bereiche | ja (GD) | ja (OpenCV) |
| Sammelfreigabe „Freigeben (ohne Gesichter)“ | ausgeblendet | ja |
| „Zur Freigabe holen“ für auto-abgelehnte Fotos | ausgeblendet | ja |
| Zeitraffer | nein (Befehl wird übersprungen, Flag bleibt) | ja |

Zentrale Umschaltstelle im Code: `App\Support\Pipeline::workerEnabled()` (`app/Support/Pipeline.php`).

---

## 2. Erfassungsmöglichkeiten der Station und ihre Funktionen

### 2.1 Physische Station (Sicht des Passanten)

1. Handy in den Rahmen legen, Hauptkamera nach vorn.
2. Foto ohne Zoom aufnehmen, möglichst ohne Personen.
3. QR-Code scannen ⇒ `GET /s/{kurzname}` ⇒ Foto hochladen.

Der QR-Code wird im Admin pro Station als SVG erzeugt (`App\Support\QrCode`, Fehlerkorrektur **Q** = 25 %, damit der Code im Außenbereich auch verschmutzt/zerkratzt lesbar bleibt).

### 2.2 Upload-Formular (`resources/views/upload/show.blade.php`)

| Feld | Pflicht | Funktion |
| --- | --- | --- |
| **Foto** (`photo`) | ja | `accept="image/jpeg,image/png,image/webp"`, `capture="environment"` öffnet auf dem Handy direkt die Rückkamera. iOS liefert bei dieser `accept`-Liste automatisch JPEG statt HEIC. Clientseitige Vorschau per `URL.createObjectURL` (`resources/js/app.js`). |
| **Name** (`name`) | nein | Max. 40 Zeichen. Wird in der Galerie unter dem Foto angezeigt; leer = anonym. |
| **Zustimmung** (`consent`) | ja | Checkbox mit dem aktuell gültigen Zustimmungstext (CC0-Freigabe, Altersbestätigung). Die Version wird mit der Einreichung gespeichert. |
| **ALTCHA-Widget** (`altcha`) | ja, wenn aktiviert | „Ich bin kein Roboter“ – Proof of Work im Browser, startet beim Fokus (`auto="onfocus"`). |
| Honeypot (versteckt) | – | Unsichtbares Feld + Zeitstempel von `spatie/laravel-honeypot` (`<x-honeypot />`). |

Der Submit-Button wird nach dem Absenden deaktiviert („Wird hochgeladen …“), um Doppel-Uploads zu verhindern.

Ist die Station nicht bereit (inaktiv, kein Referenzbild oder kein gültiger Zustimmungstext), zeigt die Seite nur den Hinweis „Diese Station nimmt gerade noch keine Fotos an“.

### 2.3 Stammdaten einer Station (Admin → Stationen)

Nur für Rolle **Admin** sichtbar (`StationResource::canViewAny`). Felder (`app/Filament/Resources/Stations/StationResource.php`, Tabelle `stations`):

| Feld | DB-Spalte | Standard | Funktion |
| --- | --- | --- | --- |
| Name | `name` | – | Anzeigename in Galerie und Upload-Seite. Beim Anlegen wird daraus der Kurzname vorgeschlagen. |
| Kurzname in der URL | `slug` | aus Name | Route-Key: `/s/{slug}` (Upload) und `/stationen/{slug}` (Galerie). **Steht im QR-Code – nach dem Druck des Schilds nicht mehr ändern.** Eindeutig, `alpha_dash`, max. 64 Zeichen. |
| Beschreibung | `description` | – | Text auf Upload- und Galerieseite. |
| Breiten-/Längengrad | `lat`, `lng` | – | Standort (derzeit nur gespeichert, nicht angezeigt). |
| Aktiv | `is_active` | `true` | Inaktive Stationen liefern 404 auf Upload und Galerie und tauchen in der Übersicht nicht auf. |
| Referenzbild | `reference_path` | – | Scharfes Foto aus dem Rahmen bei Tageslicht, liegt im **privaten** Bucket unter `references/`. Ohne Referenzbild nimmt die Station keine Uploads an (`Station::isReady()`). Im vollen Modus wird jedes Foto damit abgeglichen und darauf ausgerichtet. |
| Breite / Höhe der Ausgabe | `output_width`, `output_height` | 1920 × 1440 | Zielformat aller ausgerichteten/zugeschnittenen Bilder und des Zeitraffers. Seitenverhältnis wie das Referenzbild wählen. |
| Mindest-Inlier | `min_inliers` | 40 | Schwelle für den Bildabgleich (nur voller Modus). Weniger übereinstimmende Merkmale ⇒ `auto_rejected`. Echte Fotos sollten sicher darüber liegen, fremde Bilder liegen in Tests meist unter 15. |
| (intern) | `needs_render` | `false` | Wird bei Veröffentlichung/Entfernung gesetzt; der nächtliche Befehl rendert dann den Zeitraffer neu. In der Tabelle als „Zeitraffer veraltet“ sichtbar. |

**Aktionen in der Stationstabelle**

| Aktion | Wirkung |
| --- | --- |
| Bearbeiten | Stammdaten ändern |
| QR-Code | Download `fotostation-{slug}-qr.svg` mit der Upload-URL |
| Zeitraffer neu erstellen | Setzt nur `needs_render = true`; gerendert wird in der nächsten Nacht oder sofort per `php artisan fotostation:render-changed` |

### 2.4 Weitere Erfassungsbereiche im Admin

| Bereich | Rolle | Inhalt |
| --- | --- | --- |
| **Dashboard** | alle | Widget `ModerationStats`: wartet auf Freigabe, Uploads heute, Anteil automatisch abgelehnt (30 Tage), offene Meldungen |
| **Einreichungen** | Admin + Moderation | Liste (Filter Status/Station, Standard „Wartet auf Freigabe“), Detailansicht mit ausgerichtetem Bild, Gesichtsboxen (rot), manuellen Boxen (blau gestrichelt) und Referenzbild daneben. Navigation zeigt Anzahl offener Freigaben als Badge. Anlegen/Bearbeiten ist gesperrt. |
| **Meldungen** | Admin + Moderation | Meldungen nach Art. 16 DSA; Aktionen „Foto ansehen“, „Foto entfernen“, „Zurückweisen“ |
| **Zustimmungstexte** | nur Admin | Versionierte, **unveränderliche** Texte (Version, Lizenz CC0 1.0, gültig ab, Text). Änderung = neue Version anlegen. Gültig ist die jüngste Version mit `valid_from <= now()`. Ein `updating`-Hook im Modell wirft eine Exception. Die erste Version `2026-11` legt eine Migration an. |

**Rollen** (`App\Enums\UserRole`): `admin` (alles) und `moderator` (Einreichungen, Meldungen, Dashboard). Eine Selbstregistrierung gibt es nicht; Zugänge entstehen nur über `fotostation:create-admin`.

### 2.5 Moderationsaktionen (`app/Filament/Resources/Submissions/SubmissionActions.php`)

| Aktion | sichtbar bei Status | Wirkung |
| --- | --- | --- |
| **Freigeben** | `pending_review` | Optional zusätzliche Verpixel-Bereiche in **Prozent** des Bildes (x/y = linke obere Ecke, b/h). Status ⇒ `approved`, Job `PublishSubmission` wird eingereiht. |
| **Ablehnen** | `pending_review`, `auto_rejected`, `failed` | Grund aus Liste (Personen erkennbar, Nicht von der Station, Schlechte Qualität, Unangemessen, Duplikat). **Alle Dateien werden sofort gelöscht**, Status ⇒ `rejected`. |
| **Zur Freigabe holen** | `auto_rejected` mit Homographie, nur voller Modus | Startet die Kette ab `AlignImage` (Stichprobe der automatischen Ablehnungen). |
| **Erneut verarbeiten** | `failed`, oder `approved` seit > 10 Min. | `failed` ⇒ Kette von vorn; `approved` ⇒ `PublishSubmission` erneut. |
| **Entfernen** | `published` | Löscht Dateien, Status ⇒ `rejected`, Station ⇒ `needs_render = true`. |
| **Freigeben (ohne Gesichter)** (Sammelaktion) | nur voller Modus | Gibt nur ausgewählte Fotos frei, die `pending_review` sind **und** keine erkannten Gesichter haben. |

---

## 3. Ablauf und Absicherung eines Uploads

### 3.1 Statusmodell (`App\Enums\SubmissionStatus`)

```
received ──▶ processing ──▶ pending_review ──▶ approved ──▶ published
                 │                 │                            │
                 ▼                 ▼                            ▼
           auto_rejected        rejected  ◀───── (Entfernen) ───┘
                 │
   (jeder Schritt kann bei technischem Fehler) ──▶ failed
```

Nur in `received` und `processing` darf die Verarbeitungskette arbeiten (`isProcessable()`). Jeder Job prüft das zu Beginn erneut – wird ein Foto währenddessen abgelehnt, laufen die restlichen Jobs ins Leere (idempotent).

### 3.2 Request-Phase: `POST /s/{station}`

Reihenfolge der Schutzmechanismen, wie sie im Request durchlaufen werden:

| # | Schutz | Ort | Verhalten bei Verstoß |
| --- | --- | --- | --- |
| 1 | **Rate-Limit** `throttle:uploads` | `AppServiceProvider::boot()` | HTTP 429. Zwei Limits: **3 pro Stunde je IP und Station** und **500 pro Tag je Station**. Schlüssel enthalten nur einen HMAC-Hash der IP. *Hinweis: Jeder POST zählt, auch einer mit Validierungsfehler.* |
| 2 | **Honeypot** `ProtectAgainstSpam` | `config/honeypot.php` | Ausgefülltes verstecktes Feld oder Absenden schneller als 3 s nach dem Laden ⇒ leere Seite (`BlankPageResponder`), keine Fehlermeldung. |
| 3 | **CSRF** | Laravel-Standard (`@csrf`) | HTTP 419 |
| 4 | **Validierung** | `StoreSubmissionRequest` | Fehlermeldungen auf Deutsch zurück ins Formular: Foto Pflicht; MIME `image/jpeg`, `image/png`, `image/webp` (HEIC bewusst nicht); max. 15 360 KB; kürzere Kante ≥ 1000 px; Zustimmung `accepted`; Name ≤ 40 Zeichen. |
| 5 | **ALTCHA** | `App\Rules\Altcha` + `App\Services\Altcha` | Regel ist `implicit`, läuft also auch, wenn das Feld fehlt (Umgehen durch Weglassen unmöglich). Prüft HMAC-Signatur, Lösung und Ablaufzeit, **jede Aufgabe gilt nur einmal** (Signatur wird per `Cache::add` bis zum Ablauf gesperrt ⇒ kein Replay). |
| 6 | **Station bereit** | `UploadController::store` | 404, wenn inaktiv oder ohne Referenzbild; 503, wenn kein gültiger Zustimmungstext existiert. |
| 7 | **Neu-Kodieren** (synchron) | `App\Support\ImageProcessor` | Siehe 3.3. Nicht dekodierbare Dateien ⇒ Validierungsfehler am Feld `photo`. |
| 8 | **Duplikatprüfung** | `UploadController::isDuplicate` | Siehe 3.4. Duplikate werden **still verworfen**: Der Nutzer sieht trotzdem die Danke-Seite (kein Orakel für Angreifer). |
| 9 | **Speichern** | `PhotoStorage::putPrivate` + DB-Transaktion | Erst Dateien in den privaten Bucket, dann DB-Datensatz. Scheitert das Speichern in der DB, werden die Dateien wieder gelöscht. `put()`-Rückgabewerte werden geprüft (Laravel Cloud konfiguriert Disks mit `throw => false`). |
| 10 | **Kette starten** | `Pipeline::dispatch` | Siehe 3.5 |
| 11 | **Danke-Seite** | `GET /s/{station}/danke` | Nur mit **signierter URL** (30 Minuten gültig, Middleware `signed`) – die Seite ist nicht direkt aufrufbar. |

### 3.3 Neu-Kodieren und Datenminimierung (`ImageProcessor::process`)

Bevor irgendetwas gespeichert wird:

1. Format prüfen per `getimagesize` (nur JPEG/PNG/WebP).
2. Aufnahmedatum aus EXIF `DateTimeOriginal` (Fallback `DateTimeDigitized`) lesen, in `APP_TIMEZONE` interpretieren. Offensichtlich falsche Werte (vor 2000 oder mehr als einen Tag in der Zukunft) werden verworfen.
3. Drehen/Spiegeln gemäß EXIF-`Orientation` (alle 8 Fälle).
4. Auf max. **3000 px** längste Kante verkleinern; danach erneute Prüfung kürzeste Kante ≥ 1000 px.
5. Neu als progressives **JPEG (Qualität 88)** kodieren, Transparenz auf Weiß. **Dabei gehen alle Metadaten verloren, inklusive GPS.** Nur das Aufnahmedatum bleibt als DB-Feld `taken_at` erhalten.
6. **WebP-Vorschau** (480 px breit, Qualität 80) für die Moderation.
7. **SHA-256** der neu kodierten Datei und **Perceptual Hash** (dHash, 64 Bit) berechnen.

Ergebnis: Es landet nie eine Originaldatei mit Metadaten im Speicher.

Weitere Datenschutzmaßnahmen rund um den Upload:

- Die IP-Adresse wird nur als `hash_hmac('sha256', ip, APP_KEY)` gespeichert (`ip_hash`, im Modell `Hidden`) und nach 48 Stunden gelöscht (`fotostation:purge-ip-hashes`).
- Nach der Veröffentlichung werden Original, Vorschau und ausgerichtete Fassung gelöscht (`Submission::deletePrivateFiles`).
- Abgelehnte Fotos: Dateien sofort gelöscht, DB-Datensatz in der nächsten Nacht (`model:prune`).

### 3.4 Duplikaterkennung

Verglichen wird nur innerhalb derselben Station und nur mit nicht abgelehnten Einreichungen:

1. **Exakt:** gleicher SHA-256 ⇒ Duplikat (zeitlich unbegrenzt).
2. **Ähnlich:** gleicher IP-Hash, innerhalb der letzten 24 h, Hamming-Abstand der dHashes ≤ `duplicate_distance` (3 von 64 Bit).

Bewusst **kein** globaler Ähnlichkeitsvergleich: Fotos derselben Station ähneln sich naturgemäß stark und würden sonst fälschlich als Duplikat gelten.

### 3.5 Asynchrone Verarbeitungskette (Queue)

Alle Jobs laufen auf **einer** Queue (`FOTOSTATION_QUEUE`, Standard `uploads`) als `Bus::chain`. Basisklasse `App\Jobs\SubmissionJob`:

- `tries = 3`, `backoff = [30, 120, 600]` Sekunden
- `timeout = 85` s – unter der 90-s-Grenze der Flex-Worker von Laravel Cloud Managed Queues
- `failed()` setzt den Status auf `failed` und speichert Job-Name + Fehlermeldung (max. 1000 Zeichen) im Feld `error`

**Voller Modus (`CV_WORKER_ENABLED=true`)**

| Job | Worker-Endpunkt | Ergebnis |
| --- | --- | --- |
| `MatchReference` | `POST /match` | Status ⇒ `processing`. SIFT-Merkmale (max. 5000, CLAHE-Kontrastausgleich), FLANN-Matching mit Ratio-Test 0,75, Homographie per RANSAC. Speichert `match_inliers`, `match_score`, `homography`. Bei `inliers < min_inliers` oder unplausibler Homographie (Spiegelung, nicht konvex, Fläche außerhalb 15 %–600 % der Referenz) ⇒ `auto_rejected` mit Grund „Passt nicht zum Referenzbild der Station.“ – die Kette bleibt stehen. |
| `AlignImage` | `POST /align` | `warpPerspective` auf die Referenzgröße, dann Skalierung auf `output_width × output_height`. Speichert `aligned/{station}/{id}.jpg` im privaten Bucket. |
| `DetectFaces` | `POST /faces` | YuNet-Gesichtserkennung im **ausgerichteten** Bild (Score ≥ 0,6), Boxen mit 25 % Rand ⇒ `face_boxes` (Pixel). |
| `MarkForReview` | – | Status ⇒ `pending_review` |

**Einfacher Modus (`CV_WORKER_ENABLED=false`)**

| Job | Ergebnis |
| --- | --- |
| `PrepareImage` | Status ⇒ `processing`. Mittiger „Cover“-Zuschnitt mit GD (`SimpleImageEditor::cover`) auf das Stationsformat ⇒ `aligned/…jpg` |
| `MarkForReview` | Status ⇒ `pending_review` |

**Nach der Freigabe: `PublishSubmission`** (läuft nur bei Status `approved`)

1. Boxen sammeln: `face_boxes` (Pixel) + `manual_boxes` (Prozent ⇒ Pixel umgerechnet).
2. Voller Modus: `POST /blur` – Mosaik (12 Blöcke) + Gaußscher Weichzeichner, nicht umkehrbar. Einfacher Modus: GD-Mosaik.
3. Schreibt öffentliches JPEG `{station}/{id}.jpg` und Thumbnail `{station}/thumbs/{id}.webp` in den **öffentlichen** Bucket.
4. Status ⇒ `published`, `published_at` setzen.
5. Private Dateien löschen.
6. Station ⇒ `needs_render = true`.

Schlägt `PublishSubmission` fehl, bleibt das Foto `approved` und kann nach 10 Minuten über „Erneut verarbeiten“ neu veröffentlicht werden.

### 3.6 Absicherung der Kommunikation mit dem cv-worker

| Maßnahme | Umsetzung |
| --- | --- |
| Authentifizierung | `Authorization: Bearer <CV_WORKER_TOKEN>`, Vergleich mit `secrets.compare_digest` (zeitkonstant). Ohne gesetztes `WORKER_TOKEN` antwortet der Worker mit 503 statt offen zu sein. |
| Keine Zugangsdaten auf dem VPS | Der Worker bekommt nur **vorsignierte URLs** (Lesen per GET, Schreiben per PUT), gültig 15 Minuten (`url_ttl_minutes`). |
| URL-Whitelist | `ALLOWED_URL_PREFIXES` beschränkt Downloads/Uploads auf die eigenen Bucket-Hosts (Schutz gegen SSRF). |
| Größenlimit | Downloads max. `MAX_DOWNLOAD_MB` (30 MB), Request-Body am Caddy max. 5 MB. Keine Redirects (`follow_redirects=False`). |
| Transport | Caddy mit automatischem TLS, `Server`-Header entfernt. |
| Container-Härtung | `read_only: true`, `tmpfs /tmp` (2 GB), `no-new-privileges`, 2 GB Speicherlimit, eigener System-User (UID 10001). |
| Logging | `httpx`-Logger auf WARNING, damit keine vorsignierten URLs ins Log gelangen. Keine OpenAPI-Doku (`docs_url=None`). |
| Laravel-seitig | Connect-Timeout 5 s, Timeout 60 s, nur Verbindungsfehler werden sofort (2×) wiederholt; alles andere übernimmt der Job-Backoff. |

### 3.7 Sonstige öffentliche Endpunkte und ihre Absicherung

| Route | Schutz |
| --- | --- |
| `GET /altcha/challenge` | `throttle:altcha` (30/Minute je IP-Hash), `Cache-Control: no-store`, 404 wenn ALTCHA aus |
| `POST /fotos/{id}/melden` | `throttle:reports` (5/Stunde je IP-Hash), Honeypot, Validierung (Grund 10–2000 Zeichen), nur für veröffentlichte Fotos; E-Mail an `FOTOSTATION_ADMIN_EMAIL` |
| `/admin` | Filament-Login, nur Benutzer mit Rolle |
| `/up` | Laravel-Health-Check |

---

## 4. Optionale Einstellungen (Konfigurationsreferenz)

Alle Fotostation-Einstellungen stehen in `config/fotostation.php`. Variablen ohne Eintrag in `.env.example` sind mit ✱ markiert – sie funktionieren trotzdem, sind aber nur bei Bedarf zu setzen.

### 4.1 Upload

| Variable | Standard | Wirkung |
| --- | --- | --- |
| `FOTOSTATION_UPLOAD_MAX_KB` ✱ | `15360` | Maximale Dateigröße in KB. **PHP-Limits** (`upload_max_filesize`, `post_max_size`) müssen mindestens so groß sein. |
| `FOTOSTATION_UPLOAD_MIN_EDGE` ✱ | `1000` | Mindestlänge der kürzeren Kante in px (vor und nach dem Verkleinern geprüft) |
| `FOTOSTATION_UPLOAD_MAX_EDGE` ✱ | `3000` | Längste Kante nach dem Neu-Kodieren |
| `FOTOSTATION_UPLOADS_PER_IP_HOUR` ✱ | `3` | Rate-Limit je IP und Station pro Stunde |
| `FOTOSTATION_UPLOADS_PER_STATION_DAY` ✱ | `500` | Rate-Limit je Station pro Tag |
| (nur Config) `jpeg_quality` | `88` | JPEG-Qualität beim Neu-Kodieren |
| (nur Config) `thumb_width` | `480` | Breite von Moderations-Vorschau und öffentlichem Thumbnail |
| (nur Config) `duplicate_distance` | `3` | Max. Hamming-Abstand (0–64) für ähnliche Duplikate derselben IP |

### 4.2 Spamschutz

| Variable | Standard | Wirkung |
| --- | --- | --- |
| `ALTCHA_ENABLED` | `true` | ALTCHA ein/aus. Aus ⇒ Widget fehlt, `/altcha/challenge` liefert 404, Regel lässt alles durch. |
| `ALTCHA_HMAC_KEY` | leer | Signaturschlüssel. Leer ⇒ abgeleitet aus `APP_KEY` (`hash_hmac('sha256', 'altcha', APP_KEY)`). Achtung: Ein Wechsel von `APP_KEY` macht offene Aufgaben ungültig. |
| `ALTCHA_COST` | `5000` | PBKDF2-Iterationen = Rechenaufwand im Browser. Höher = teurer für Bots, aber langsamer auf alten Handys. |
| `ALTCHA_EXPIRES` ✱ | `1800` | Gültigkeit einer Aufgabe in Sekunden (mindestens 60) |
| `HONEYPOT_ENABLED` ✱ | `true` | Honeypot ein/aus |
| `HONEYPOT_NAME` ✱ | `my_name` | Name des versteckten Felds (wird zusätzlich randomisiert, `HONEYPOT_RANDOMIZE`) |
| `HONEYPOT_SECONDS` ✱ | `3` | Mindestzeit zwischen Laden und Absenden des Formulars |

### 4.3 Bildverarbeitungs-Worker (Laravel-Seite)

| Variable | Standard | Wirkung |
| --- | --- | --- |
| `CV_WORKER_ENABLED` | `false` | Schaltet zwischen einfachem und vollem Modus um |
| `CV_WORKER_URL` | `http://localhost:8000` | Basis-URL des Workers (ohne Slash am Ende) |
| `CV_WORKER_TOKEN` | – | Gleicher Wert wie `WORKER_TOKEN` auf dem VPS (`openssl rand -hex 32`) |
| `CV_WORKER_TIMEOUT` | `60` | HTTP-Timeout der Queue-Jobs in Sekunden. **Unter 90 s halten** (Laravel-Cloud-Grenze). |
| `CV_WORKER_RENDER_TIMEOUT` ✱ | `900` | HTTP-Timeout für das Zeitraffer-Rendern (läuft synchron im Befehl, nicht in der Queue) |
| (nur Config) `url_ttl_minutes` | `15` | Gültigkeit der vorsignierten URLs |

### 4.4 cv-worker (VPS, `cv-worker/.env`)

| Variable | Standard | Wirkung |
| --- | --- | --- |
| `WORKER_DOMAIN` | – | Domain für Caddy/TLS (DNS-A-Record auf den VPS) |
| `WORKER_TOKEN` | – | Bearer-Token, Pflicht |
| `ALLOWED_URL_PREFIXES` | leer (= alle) | Kommagetrennte erlaubte URL-Präfixe. **Privaten und öffentlichen Bucket-Host eintragen** – der Zeitraffer lädt die Frames über die öffentlichen URLs. |
| `MATCH_EDGE` | `1600` | Kantenlänge, auf die für den Bildabgleich verkleinert wird |
| `MATCH_RATIO` | `0.75` | Lowe-Ratio-Test beim Matching (kleiner = strenger) |
| `RANSAC_THRESHOLD` ✱ | `5.0` | Reprojektionsfehler (px) für RANSAC |
| `FACE_SCORE` | `0.6` | Mindest-Konfidenz der Gesichtserkennung (kleiner = mehr Treffer, mehr Fehlalarme) |
| `FACE_MODEL` ✱ | `/models/face_detection_yunet_2023mar.onnx` | Pfad zum YuNet-Modell (im Dockerfile mit Prüfsumme geladen) |
| `MAX_DOWNLOAD_MB` | `30` | Max. Größe eines geladenen Bildes |
| `LOG_LEVEL` | `INFO` | Log-Level |
| `URL_REWRITE` | – | Nur lokal: `http://localhost:9000=>http://minio:9000` |

### 4.5 Speicher, Queue, Zeitplan, Aufbewahrung, Betreiber

| Variable | Standard | Wirkung |
| --- | --- | --- |
| `FOTOSTATION_PRIVATE_DISK` ✱ | `photos-private` | Disk-Name des privaten Buckets |
| `FOTOSTATION_PUBLIC_DISK` ✱ | `photos-public` | Disk-Name des öffentlichen Buckets |
| `PHOTOS_*` | MinIO-Werte | Nur lokal. In Laravel Cloud **nicht setzen** – die angehängten Buckets überschreiben die Disks. |
| `FOTOSTATION_QUEUE` | `uploads` | Name der (einzigen) Queue |
| `FOTOSTATION_NIGHTLY_AT` | `03:00` | Uhrzeit aller nächtlichen Aufgaben |
| `FOTOSTATION_RENDER_FPS` | `4` | Bilder pro Sekunde im Zeitraffer |
| `FOTOSTATION_RENDER_MAX_FRAMES` ✱ | `1500` | Max. Bilder pro Zeitraffer; darüber wird gleichmäßig ausgedünnt |
| (nur Config) `retention.ip_hash_hours` | `48` | IP-Hashes löschen nach … Stunden (effektiv max. 72 h, da nur nachts) |
| (nur Config) `retention.auto_rejected_days` | `7` | Automatisch abgelehnte Einreichungen löschen nach … Tagen |
| (nur Config) `retention.failed_days` | `30` | Fehlgeschlagene Einreichungen löschen nach … Tagen |
| `FOTOSTATION_ADMIN_EMAIL` | leer | Empfänger für Meldungen und Fehlerberichte; leer = nur Log |
| `FOTOSTATION_OPERATOR` ✱ | `Stadtverein Simbach am Inn e.V.` | Betreibername in Kopfzeile/Titel |
| `FOTOSTATION_SERIES_CREDIT` ✱ | `Fotostation Stadtverein … und Mitwirkende` | Namensnennung unter dem Zeitraffer (CC BY 4.0) |

---

## 5. Laravel-Befehle (Artisan)

Die projektspezifischen Befehle sind als Closure-Commands in `routes/console.php` definiert.

### 5.1 `fotostation:create-admin`

```bash
php artisan fotostation:create-admin {email} {name} [--password="…"] [--moderator]
```

- Legt einen Zugang an oder aktualisiert ihn (`updateOrCreate` über die E-Mail).
- Ohne `--password` wird interaktiv gefragt; in nicht interaktiven Umgebungen (Laravel Cloud „Commands“) ist `--password` Pflicht.
- Passwort mindestens 12 Zeichen.
- `--moderator` ⇒ Rolle `moderator`, sonst `admin`.
- Mit `--password` warnt der Befehl, dass das Passwort im Befehlsverlauf steht ⇒ nach der ersten Anmeldung ändern.

### 5.2 `fotostation:render-changed`

```bash
php artisan fotostation:render-changed [--all]
```

- Rendert die Zeitraffer aller Stationen mit `needs_render = true` – **synchron** im Befehl, nicht in der Queue (Rendern dauert länger als 90 s).
- `--all`: alle aktiven Stationen, unabhängig vom Flag.
- Bei `CV_WORKER_ENABLED=false` wird übersprungen; `needs_render` bleibt gesetzt, damit nach dem Einschalten nachgeholt wird.
- Exit-Code `1`, wenn mindestens eine Station fehlschlug.
- Läuft nachts mit `withoutOverlapping(60)`.

### 5.3 `fotostation:purge-ip-hashes`

```bash
php artisan fotostation:purge-ip-hashes
```

Setzt `ip_hash` auf `NULL` für alle Einreichungen, die älter als `retention.ip_hash_hours` (48 h) sind. Gibt die Anzahl aus.

### 5.4 `fotostation:report-stuck`

```bash
php artisan fotostation:report-stuck
```

Zählt fehlgeschlagene Einreichungen der letzten 24 h und „hängende“ (`received`/`processing`, seit über 1 h unverändert). Wenn etwas gefunden wird: Warnung ins Log und E-Mail an `FOTOSTATION_ADMIN_EMAIL`.

### 5.5 Genutzte Standardbefehle

| Befehl | Zweck in diesem Projekt |
| --- | --- |
| `php artisan model:prune --model="App\Models\Submission"` | Löschfristen: `auto_rejected` nach 7 Tagen, `rejected` sofort (nächste Nacht), `failed` nach 30 Tagen. Vor dem Löschen werden alle Dateien entfernt (`pruning()`). |
| `php artisan queue:work --queue=uploads` | Lokaler Queue-Worker (in Laravel Cloud übernimmt das die Managed Queue) |
| `php artisan schedule:work` | Lokal den Scheduler laufen lassen |
| `php artisan migrate [--force]` | Datenbank; legt auch den ersten Zustimmungstext an |
| `php artisan test` | PHPUnit-Tests (`tests/Feature`, `tests/Unit`) |
| `php artisan optimize`, `php artisan filament:optimize` | Build in Laravel Cloud |

`php artisan queue:failed` funktioniert mit Laravel Cloud Managed Queues nicht – fehlgeschlagene Jobs stehen dort unter **Monitoring › Queues**; zusätzlich ist der Status `failed` im Admin sichtbar.

### 5.6 Nächtlicher Zeitplan

Alle Aufgaben laufen gebündelt um `FOTOSTATION_NIGHTLY_AT` (Standard 03:00), damit die hibernate-fähige Datenbank nur einmal am Tag aufwacht:

```php
Schedule::command('model:prune', ['--model' => [Submission::class]])->dailyAt($at);
Schedule::command('fotostation:purge-ip-hashes')->dailyAt($at);
Schedule::command('fotostation:render-changed')->dailyAt($at)->withoutOverlapping(60);
Schedule::command('fotostation:report-stuck')->dailyAt($at);
```

---

## 6. Was man mit den Bildern machen kann

### 6.1 Galerie (`GET /stationen/{slug}`)

- Alle veröffentlichten Fotos einer Station, chronologisch nach `coalesce(taken_at, created_at)`, 48 pro Seite.
- Thumbnail (WebP) mit Link auf das volle JPEG, Datum und optionalem Namen.
- Link „Melden“ je Foto (Art. 16 DSA).
- Einzelfotos stehen unter **CC0 1.0** (laut Zustimmungstext).
- `GET /` leitet direkt zur Station weiter, solange es nur eine aktive gibt; sonst Übersicht `/stationen`.

### 6.2 Vorher/Nachher-Vergleich

Auf der Galerieseite, sobald es mindestens zwei veröffentlichte Fotos gibt: ältestes und neuestes Foto übereinander, mit Schieberegler (CSS `clip-path`). Funktioniert in **beiden** Modi, sieht aber nur im vollen Modus wirklich deckungsgleich aus, weil dort alle Bilder auf die Referenz ausgerichtet sind. Benötigt keinen Worker.

### 6.3 Zeitraffer

Aus allen veröffentlichten Fotos einer Station entsteht ein MP4-Video, das auf der Galerieseite und auf der Danke-Seite nach dem Upload eingebettet wird (`<video muted loop playsinline>`), mit Zeitraum, Anzahl der Fotos, Namensnennung und Lizenz **CC BY 4.0**.

**Ablauf** (`App\Jobs\RenderSeries`, aufgerufen von `fotostation:render-changed`):

1. Veröffentlichung oder Entfernung eines Fotos setzt `stations.needs_render = true`.
2. Nachts (oder manuell) lädt der Befehl alle Stationen mit gesetztem Flag.
3. Frames = alle veröffentlichten Fotos mit `public_path`, chronologisch.
4. Mehr als `FOTOSTATION_RENDER_MAX_FRAMES` (1500)? ⇒ gleichmäßig ausdünnen.
5. Weniger als 2 Frames ⇒ vorhandene Reihen löschen, Flag zurücksetzen, fertig.
6. Neuer `series`-Datensatz (Typ `timelapse`, Zeitraum `period_from`/`period_to`).
7. `POST /render` am Worker mit den öffentlichen URLs der Frames, FPS, Zielgröße und einer vorsignierten PUT-URL für `{station}/series/{id}.mp4`.
8. Worker: lädt Frames nacheinander, bringt jedes Bild per Letterbox auf exakt die Zielgröße (gerade Pixelzahlen), schreibt sie auf `/tmp` und kodiert mit **ffmpeg** (H.264/`libx264`, `preset veryfast`, `crf 23`, `yuv420p`, `+faststart`) – in allen Browsern abspielbar und sofort streambar. Einzelne fehlende Frames werden übersprungen.
9. Laravel speichert in einer Transaktion `video_path`, `frame_count`, `duration_seconds`, `rendered_at`, die Frame-Zuordnung (`series_frames` mit `position`), löscht alte Reihen samt Video und setzt `needs_render = false`.
10. Bei Fehler: unfertige Reihe wird gelöscht, das Flag bleibt ⇒ nächste Nacht neuer Versuch.

Es bleibt immer nur **die aktuelle Reihe** pro Station erhalten. Dauer = Frames / FPS (z. B. 1500 Frames bei 4 FPS = 6:15 Minuten).

**Datenmodell**

| Tabelle | Inhalt |
| --- | --- |
| `series` | `station_id`, `type` (`timelapse`), `period_from`, `period_to`, `video_path`, `frame_count`, `duration_seconds`, `license` (`cc-by-4.0`), `rendered_at` |
| `series_frames` | Pivot `series_id` ↔ `submission_id` mit `position` – nachvollziehbar, welche Fotos in welchem Video stecken |

### 6.4 Datenschutzbearbeitung der Bilder

- Automatische Gesichtserkennung + Verpixeln (voller Modus)
- Manuelle Verpixel-Bereiche (beide Modi), z. B. für Kennzeichen
- Entfernen veröffentlichter Fotos auf Meldung, inklusive Neu-Rendern des Zeitraffers

---

## 7. Benötigte Komponenten

### 7.1 Für den Grundbetrieb (Upload, Moderation, Galerie, Vorher/Nachher)

| Komponente | Details |
| --- | --- |
| PHP ≥ 8.3 (Laravel Cloud: 8.4/8.5) | Erweiterungen **`gd`** (mit JPEG-, PNG-, WebP-Unterstützung) und **`exif`** |
| Composer-Pakete | `laravel/framework` 13, `filament/filament` 5, `altcha-org/altcha`, `spatie/laravel-honeypot`, `bacon/bacon-qr-code`, `aws/aws-sdk-php`, `league/flysystem-aws-s3-v3` |
| Node/Vite | Tailwind 4, `altcha`-Widget (npm), Build per `npm ci && npm run build` |
| Datenbank | PostgreSQL (lokal 17, in der Cloud Serverless Postgres) |
| Cache | Store, der `Cache::add` atomar unterstützt (ALTCHA-Replay-Schutz und Rate-Limits); Standard `database` |
| Queue + Worker | Eine Queue (`uploads`); lokal `queue:work`, in der Cloud Managed Queue |
| Scheduler | Für Löschfristen und Berichte |
| 2 S3-Buckets | `photos-private` (privat, muss vorsignierte URLs unterstützen) und `photos-public` (öffentlich lesbar). Lokal MinIO über `docker-compose.dev.yml`. |
| Mail (optional) | Für Benachrichtigungen an `FOTOSTATION_ADMIN_EMAIL` |

### 7.2 Zusätzlich für Bildabgleich, Ausrichtung, Gesichtserkennung und Zeitraffer

| Komponente | Details |
| --- | --- |
| **cv-worker** | `cv-worker/` – Python 3.13, FastAPI, Uvicorn (2 Prozesse), `opencv-python-headless` 5 (SIFT, FLANN, `FaceDetectorYN`), NumPy, httpx |
| **ffmpeg** mit `libx264` | Im Docker-Image per `apt-get` installiert – nur für den Zeitraffer |
| **YuNet-Modell** | `face_detection_yunet_2023mar.onnx` (OpenCV Model Zoo, MIT), beim Docker-Build mit SHA-256-Prüfung geladen – nur für die Gesichtserkennung |
| **VPS** | Docker + Docker Compose, ca. 2 GB RAM für den Worker, 2 GB `tmpfs` für die Zeitraffer-Frames |
| **Caddy** | Reverse Proxy mit automatischem TLS, Timeouts 15 Minuten (wegen Rendern) |
| **DNS** | A-Record `WORKER_DOMAIN` ⇒ VPS |
| **Konfiguration** | Laravel: `CV_WORKER_ENABLED=true`, `CV_WORKER_URL`, `CV_WORKER_TOKEN`. Worker: `WORKER_TOKEN`, `ALLOWED_URL_PREFIXES` (beide Bucket-Hosts). |
| **Monitoring** | `GET /health` des Workers (meldet OpenCV-Version und ob das Gesichtsmodell vorhanden ist) ins Server-Monitoring aufnehmen |

**Minimale Voraussetzungen speziell für den Zeitraffer:** Worker eingeschaltet und erreichbar, ffmpeg im Worker, Scheduler aktiv (oder manueller Aufruf von `fotostation:render-changed`), öffentlich lesbarer Bucket, dessen Host in `ALLOWED_URL_PREFIXES` steht, und mindestens **zwei** veröffentlichte Fotos an der Station.

### 7.3 Lokale Entwicklungsumgebung

```bash
composer install
cp .env.example .env && php artisan key:generate
npm install && npm run build

# PostgreSQL, MinIO (+ Buckets) und cv-worker
docker compose -f docker-compose.dev.yml up -d --build
# ohne Worker: docker compose -f docker-compose.dev.yml up -d pgsql minio minio-setup

php artisan migrate
php artisan fotostation:create-admin du@example.de "Dein Name"

php artisan serve                          # Terminal 1
php artisan queue:work --queue=uploads     # Terminal 2
php artisan fotostation:render-changed     # Zeitraffer von Hand
```

MinIO-Konsole: http://localhost:9001 (`fotostation` / `fotostation-secret`).

**Achtung Portkonflikt:** `php artisan serve` und der lokale cv-worker nutzen beide standardmäßig Port 8000. Bei aktivem Worker Laravel z. B. mit `php artisan serve --port=8080` starten (und `APP_URL` anpassen).

Tests:

```bash
php artisan test
cd cv-worker && pip install -r requirements-dev.txt && pytest
```

Laut README liefen die PHPUnit-Tests zum Zeitpunkt der Erstellung noch nie; der cv-worker ist getestet (außer der Gesichtserkennung mit echtem Modell).

### 7.4 Speicherpfade (Übersicht)

| Bucket | Pfad | Inhalt | Lebensdauer |
| --- | --- | --- | --- |
| privat | `references/…` | Referenzbilder der Stationen | dauerhaft |
| privat | `originals/{station}/{id}.jpg` | Neu kodiertes Original (ohne Metadaten) | bis Veröffentlichung/Ablehnung |
| privat | `review-thumbs/{station}/{id}.webp` | Moderationsvorschau | bis Veröffentlichung/Ablehnung |
| privat | `aligned/{station}/{id}.jpg` | Ausgerichtete bzw. zugeschnittene Fassung | bis Veröffentlichung/Ablehnung |
| öffentlich | `{station}/{id}.jpg` | Veröffentlichtes, verpixeltes Foto | bis Entfernung |
| öffentlich | `{station}/thumbs/{id}.webp` | Galerie-Thumbnail | bis Entfernung |
| öffentlich | `{station}/series/{series_id}.mp4` | Aktueller Zeitraffer | bis zum nächsten Rendern |

---

## 8. Offene Fragen / Erweiterungen

Platz für weitere Fragen und Antworten. Vorlage:

```markdown
### 8.x <Frage>

**Antwort:** …

**Relevante Dateien:** `app/…`
```

<!-- Neue Abschnitte hier anfügen -->
