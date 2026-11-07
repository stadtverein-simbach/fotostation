# Fotostation

Passanten fotografieren durch einen festen Rahmen, laden das Foto über einen QR-Code hoch, und aus den freigegebenen Fotos entstehen Bilderreihen und ein nächtlich gerenderter Zeitraffer.

- **Laravel 13 + Filament 5** (dieses Repository), gehostet in **Laravel Cloud**
- **cv-worker** (Python, FastAPI, OpenCV, ffmpeg) in `cv-worker/`, gehostet auf einem **eigenen VPS**, **optional** (siehe unten)

## Einfacher Modus ohne cv-worker

Mit `CV_WORKER_ENABLED=false` (Standard) läuft die Fotostation ganz ohne VPS, nur mit PHP/GD in Laravel Cloud. Gut zum Ausprobieren, bevor der Worker eingerichtet wird.

| | ohne Worker | mit Worker |
|---|---|---|
| Upload, Neu-Kodieren, Duplikate, Rate-Limit | ja | ja |
| Abgleich mit dem Referenzbild (Spamfilter, `auto_rejected`) | nein, alles geht in die Moderation | ja |
| Ausrichtung auf die Referenz | nein, nur mittiger Zuschnitt auf das Stationsformat | ja (Homographie, Sicherheitsrand, fehlende Ränder aus dem Referenzbild ergänzt) |
| Gesichtserkennung und automatisches Verpixeln | nein, Bereiche von Hand markieren oder ablehnen | ja |
| Verpixeln von Hand markierter Bereiche | ja (GD) | ja |
| Zeitraffer | nein (nächtlicher Befehl wird übersprungen) | ja |

Im einfachen Modus ist die Sammelfreigabe „Freigeben (ohne Gesichter)“ ausgeblendet, weil nichts geprüft wurde. Queue: `PrepareImage` → `MarkForReview`.

Später einschalten: Worker aufsetzen (siehe unten), `CV_WORKER_ENABLED=true`, `CV_WORKER_URL` und `CV_WORKER_TOKEN` setzen. Neue Uploads laufen dann durch die volle Kette; die Zeitraffer für Stationen mit veröffentlichten Fotos holt der nächste nächtliche Lauf nach (oder sofort: `php artisan fotostation:render-changed`). Bereits veröffentlichte Fotos sind nur zugeschnitten, nicht ausgerichtet.

## Ablauf

1. `GET /s/{station}`: Upload-Seite (Ziel des QR-Codes)
2. `POST /s/{station}`: Honeypot, ALTCHA (selbst gehostetes Captcha), Rate-Limit (3 pro Stunde und IP, 500 pro Tag und Station), Validierung. Das Bild wird **synchron neu kodiert**, alle Metadaten in der Datei entfallen. Gespeichert wird erst diese Fassung, zusammen mit SHA-256, Perceptual Hash und einer Vorschau.
   - Vorher übernimmt `App\Support\ExifData` eine feste Auswahl an EXIF-Angaben in die Datenbank: Aufnahmedatum (mit Zeitzone aus `OffsetTimeOriginal`, falls vorhanden) in `taken_at`; Hersteller, Modell, Software, Brennweite, Digitalzoom, Belichtung, Blende, ISO, Blitz und „nach der Aufnahme geändert“ in `exif` (JSON); die Kleinbild-Brennweite zusätzlich in `focal_length_35mm` zum Filtern. Seriennummern, Namen und Ähnliches werden nie gelesen.
   - GPS: Hat die Station Koordinaten und das Foto eine Position, wird nur der Abstand in `gps_distance` gespeichert (auf 10 m gerundet, höchstens 1000 m), nie die Koordinaten. Ab `FOTOSTATION_GPS_NEAR_DISTANCE` (Standard 200 m) ist das Foto in der Moderation markiert. Viele Browser entfernen GPS und oft auch weitere EXIF-Daten schon beim Hochladen, die Felder sind daher häufig leer.
3. Queue `uploads` (konfigurierbar über `FOTOSTATION_QUEUE`), mit Worker: `MatchReference` → `AlignImage` → `DetectFaces` → `MarkForReview`. Jeder Job bleibt unter 90 Sekunden (Grenze der Flex-Worker bei Laravel Cloud Managed Queues).
   - Passt das Foto nicht zum Referenzbild der Station, wird es `auto_rejected` (Stichprobe im Admin, Löschung nach 7 Tagen).
4. Moderation in Filament (`/admin`): Freigeben (mit optionalen zusätzlichen Verpixel-Bereichen) oder Ablehnen (Dateien werden sofort gelöscht).
5. `PublishSubmission`: Gesichter und markierte Bereiche verpixeln, öffentliche Fassung und Thumbnail schreiben, Arbeitsdateien löschen. Das Original (neu kodiert, ohne Metadaten in der Datei) bleibt mit `FOTOSTATION_KEEP_ORIGINALS=true` (Standard) privat erhalten und ist in der Moderation über „Original“ abrufbar. Gelöscht wird es bei Ablehnung oder Entfernung des Fotos. Die Datenschutzerklärung passt sich der Einstellung an.

Zur Ausrichtung (`AlignImage`): Der Worker schneidet an jeder Seite den Sicherheitsrand der Station ab (Standard 4 %). Was das Foto danach immer noch nicht abdeckt, ergänzt er weich überblendet aus dem Referenzbild, statt Randpixel zu Streifen zu ziehen. Der Anteil echter Fotopixel wird als „Abdeckung“ gespeichert. Liegt er unter `FOTOSTATION_MIN_COVERAGE` (Standard 85 %), ist das Foto in der Moderation markiert und wird von der Sammelfreigabe übersprungen.
6. Nachts um 3:00 Uhr, alles gebündelt (die Datenbank wacht nur einmal auf): Zeitraffer neu rendern (nur bei Änderungen, **synchron im Befehl**, nicht in der Queue), Löschfristen (`model:prune`), IP-Hashes löschen, Fehler melden.

Abweichung vom Architektur-Doc: Den früheren Job `ProcessUpload` (Hashes, Duplikate, Thumbnail) gibt es nicht mehr, das passiert direkt im Request zusammen mit dem Neu-Kodieren. Dadurch landet nie eine Datei mit GPS-Daten im Speicher. Die Gesichtserkennung läuft nach der Ausrichtung, damit die Boxen im ausgerichteten Bild liegen.

## Wichtig: Was ungetestet ist

Diese Anwendung wurde in einer Umgebung ohne Zugriff auf Packagist geschrieben. Deshalb:

- **PHP:** Syntax aller Dateien geprüft, Blade-Templates kompiliert, API-Aufrufe mit dem Quelltext von Laravel 13.5 und Filament 5.9 abgeglichen. **Die PHPUnit-Tests (`tests/`) liefen noch nie.** Bitte zuerst `php artisan test` ausführen und Fehler beheben.
- **cv-worker:** vollständig getestet (20 Tests mit synthetischen Bildern, inkl. Abgleich, Ausrichtung, Verpixeln, Zeitraffer, Auth, vorsignierten S3-URLs). Nicht getestet ist die Gesichtserkennung, weil das YuNet-Modell hier nicht herunterladbar war; das Dockerfile lädt es beim Build.

## Lokale Entwicklung mit Laravel Sail (empfohlen)

`docker-compose.yml` bildet die komplette Umgebung nach, inklusive cv-worker:

| Dienst | Zweck | Vom Rechner aus |
| --- | --- | --- |
| `laravel.test` | App (PHP 8.5, `artisan serve`), Vite | http://localhost, Vite :5173 |
| `queue` | `queue:listen --queue=uploads` (lädt Codeänderungen automatisch) | |
| `scheduler` | `schedule:work` (nächtliche Aufgaben) | |
| `pgsql` | PostgreSQL 18 | localhost:5432 |
| `rustfs` | S3-Speicher mit `photos-private` und `photos-public` | http://localhost:9000, Konsole :9001 |
| `s3-setup` | legt die Buckets an und macht `photos-public` öffentlich lesbar, beendet sich danach | |
| `cv-worker` | Bildverarbeitung aus `cv-worker/` | http://localhost:8001/health |
| `mailpit` | fängt alle Mails ab | http://localhost:8025 |
| `adminer` | Datenbank-Oberfläche (System „PostgreSQL“, Server `pgsql`, Benutzer `fotostation`, Passwort `secret`, Datenbank `fotostation`) | http://localhost:8080 |

RustFS statt MinIO: Die Images `minio/minio` und `minio/mc` gibt es auf Docker Hub nicht mehr. Laravel Sail nutzt inzwischen ebenfalls RustFS.

### Einmalig einrichten

```bash
# 1. Hostname für den S3-Speicher. Vorsignierte URLs sind an den Hostnamen
#    gebunden, deshalb müssen Browser, Laravel und cv-worker dieselbe Adresse
#    nutzen: http://rustfs:9000. (Windows: C:\Windows\System32\drivers\etc\hosts)
echo "127.0.0.1 rustfs" | sudo tee -a /etc/hosts

# 2. Abhängigkeiten installieren (Sail liegt in vendor/, daher vor dem ersten Start)
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
    -e COMPOSER_HOME=/tmp/composer \
    laravelsail/php84-composer:latest composer install --ignore-platform-reqs

# 3. Umgebung
cp .env.sail.example .env

# 4. Starten (der erste Build dauert einige Minuten, auch wegen des Gesichtsmodells)
./vendor/bin/sail up -d --build
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed            # Testzugänge und Teststation (siehe unten)
./vendor/bin/sail npm install
./vendor/bin/sail npm run build    # oder: sail npm run dev
```

Testdaten aus `db:seed` (nur außerhalb der Produktion, mehrfach ausführbar):

| | |
| --- | --- |
| Admin | `admin@example` / `testtesttest` |
| Moderation | `moderator@example` / `testtesttest` |
| Station | Teststation-01 unter `/s/teststation-01` (48.268725, 13.021924). Vor dem ersten Upload im Admin ein Referenzbild hochladen. |

Tipp: `alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'`.

### Im Alltag

```bash
sail up -d                                  # alles starten
sail logs -f queue cv-worker                # Verarbeitungskette beobachten
sail artisan fotostation:render-changed     # Zeitraffer sofort statt nachts
sail artisan test                           # PHPUnit (SQLite im Speicher)
sail down                                   # stoppen (Daten bleiben in den Volumes)
sail down -v                                # stoppen und alle Daten löschen
```

Ohne Worker testen: In `.env` `CV_WORKER_ENABLED=false` setzen, dann `sail artisan config:clear` und `sail restart queue`.

Hinweise:

- Fehlt der Eintrag in `/etc/hosts`, laufen Uploads und Verarbeitung trotzdem. Nur die Vorschaubilder in der Moderation und die öffentlichen Fotos laden im Browser nicht.
- Mit dem Handy im WLAN testen (QR-Code): In `.env` `APP_URL`, `PHOTOS_ENDPOINT` und `PHOTOS_PUBLIC_URL` auf die LAN-IP des Rechners umstellen. Für den Hostnamen `rustfs` bräuchte das Handy einen eigenen DNS-Eintrag, das ist noch nicht vorbereitet.
- Portkonflikte (z. B. ein lokales PostgreSQL): `APP_PORT`, `FORWARD_*` in `.env` anpassen.
- `php artisan sail:install` nicht ausführen: Es legt eine eigene `compose.yaml` an und überschreibt Werte in `.env`. Falls schon passiert: `sail down -v` (entfernt auch das Datenbank-Volume, das sonst die alten Zugangsdaten behält), `compose.yaml` löschen und `.env` neu aus `.env.sail.example` anlegen (`APP_KEY` vorher sichern).
- Meldet `migrate` „password authentication failed“, wurde das Volume `sail-pgsql` mit anderen Zugangsdaten angelegt. PostgreSQL übernimmt `DB_*` nur beim ersten Start. Abhilfe: `sail down -v && sail up -d`.

## Lokale Entwicklung ohne Sail

Achtung: `docker-compose.dev.yml` nutzt noch `minio/minio` und `minio/mc`. Diese Images gibt es auf Docker Hub nicht mehr, deshalb startet die Datei derzeit nicht. Besser die Sail-Variante oben verwenden.

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install && npm run build

# PostgreSQL, MinIO (beide Buckets) und cv-worker starten
# (ohne Worker: nur "pgsql minio minio-setup" angeben und CV_WORKER_ENABLED=false lassen)
docker compose -f docker-compose.dev.yml up -d --build

php artisan migrate
php artisan fotostation:create-admin du@example.de "Dein Name"

# in getrennten Terminals
php artisan serve
php artisan queue:work --queue=uploads

# Zeitraffer von Hand erstellen (sonst nachts per Scheduler)
php artisan fotostation:render-changed
```

Dann `/admin` öffnen, eine Station anlegen, ein Referenzbild hochladen und unter `/s/{kurzname}` ein Foto hochladen. MinIO-Konsole: http://localhost:9001 (fotostation / fotostation-secret).

Tests:

```bash
php artisan test
cd cv-worker && pip install -r requirements-dev.txt && pytest
```

## Laravel Cloud

Die ausführliche Schritt-für-Schritt-Anleitung (inkl. IONOS-VPS) liegt als Dokument im Vereinsprojekt. Kurzfassung:

1. Anwendung in Region **EU (Frankfurt)** anlegen, PHP 8.4 oder 8.5.
2. **App-Cluster:** Flex, Scale to Zero an (Sleep timeout mindestens 10 Minuten), **Scheduler** an.
3. **Datenbank:** Laravel Serverless Postgres mit Hibernation.
4. **Buckets:** zwei Laravel-Object-Storage-Buckets, Jurisdiktion **European Union**:
   - Sichtbarkeit privat, Disk-Name **`photos-private`**
   - Sichtbarkeit öffentlich, Disk-Name **`photos-public`**
5. **Managed Queue:** Name `uploads`, Standard, Flex, 512 MiB, 0 bis 2 Worker. Laravel Cloud setzt `QUEUE_CONNECTION=cloud`.
6. **Umgebungsvariablen** (siehe `.env.example`): `APP_URL`, `APP_LOCALE=de`, `SESSION_DRIVER=cookie`, `CACHE_STORE=database`, `CV_WORKER_ENABLED` (zunächst `false`; erst mit Worker `true` plus `CV_WORKER_URL`, `CV_WORKER_TOKEN`, `CV_WORKER_TIMEOUT=60`), `ALTCHA_*` (optional, funktioniert ohne Einstellungen), `FOTOSTATION_ADMIN_EMAIL`, Mail-Zugang. Die `PHOTOS_*`-Variablen nicht setzen, sie gelten nur lokal.
7. **Build commands:** `composer install --no-dev --optimize-autoloader && npm ci && npm run build && php artisan optimize && php artisan filament:optimize`
8. **Deploy commands:** `php artisan migrate --force`
9. Nach dem Deployment unter **Commands**:
   `php artisan fotostation:create-admin du@example.de "Dein Name" --password="…"` (danach Passwort ändern, der Befehl bleibt im Verlauf sichtbar).

Grenzen der Managed Queues, auf die der Code ausgelegt ist: eine Queue pro Umgebung im Starter-Plan, Jobs höchstens 90 Sekunden auf Flex, Verzögerungen höchstens 15 Minuten. `php artisan queue:failed` funktioniert damit nicht; fehlgeschlagene Jobs stehen unter **Monitoring › Queues**.

Hinweis zu Filament-Uploads (Referenzbild): Livewire legt Uploads zunächst lokal ab. Bei mehreren App-Instanzen in `config/livewire.php` den `temporary_file_upload.disk` auf `photos-private` stellen.

## cv-worker auf dem VPS

```bash
cd cv-worker
cp .env.example .env    # WORKER_DOMAIN, WORKER_TOKEN, ALLOWED_URL_PREFIXES setzen
docker compose up -d --build
curl https://$WORKER_DOMAIN/health
```

- Caddy holt das TLS-Zertifikat automatisch (DNS-A-Record auf den VPS).
- `GET /health` ins Server-Monitoring aufnehmen.
- Der Worker speichert nichts und hat keine Bucket-Zugangsdaten. Er bekommt nur vorsignierte URLs (15 Minuten gültig).
- `ALLOWED_URL_PREFIXES` auf die Bucket-Hosts beschränken, damit der Worker nur von dort lädt.

## Erste Schritte nach dem Deployment

1. Station anlegen (Admin → Stationen): Name, Kurzname (steht im QR-Code, später nicht mehr ändern), Referenzbild aus dem Rahmen bei Tageslicht, Ausgabegröße im Seitenverhältnis des Referenzbilds.
2. QR-Code als SVG herunterladen (Aktion „QR-Code“) und aufs Schild bringen.
3. Ein paar Testfotos hochladen und unter „Einreichungen“ die Inlier-Werte ansehen. `Mindest-Inlier` so einstellen, dass echte Fotos sicher darüber liegen (Startwert 40). Fremde Bilder liegen in Tests meist unter 15. Zeigen viele Fotos eine geringe Abdeckung, den `Sicherheitsrand` der Station erhöhen.
4. Impressum, Datenschutz und Nutzungsbedingungen in `resources/views/pages/` vervollständigen (TODO-Kommentare) und prüfen lassen.

## Struktur

| Pfad | Inhalt |
| --- | --- |
| `app/Http/Controllers/UploadController.php` | Upload, Duplikatprüfung, Start der Verarbeitungskette |
| `app/Support/ImageProcessor.php` | Neu kodieren (GD), EXIF-Datum, Orientierung, Thumbnail, dHash |
| `app/Services/CvWorker.php`, `PhotoStorage.php` | Worker-Client, Buckets, vorsignierte URLs |
| `app/Jobs/` | Verarbeitungsschritte, Veröffentlichung, Zeitraffer |
| `app/Filament/` | Moderation, Stationen, Meldungen, Zustimmungstexte, Dashboard |
| `routes/console.php` | Nächtliche Aufgaben, `fotostation:create-admin` |
| `config/fotostation.php` | Alle Einstellungen der Fotostation |
| `cv-worker/` | Python-Worker mit Dockerfile, Caddy und Tests |
