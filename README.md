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
| Ausrichtung auf die Referenz | nein, nur mittiger Zuschnitt auf das Stationsformat | ja (Homographie) |
| Gesichtserkennung und automatisches Verpixeln | nein, Bereiche von Hand markieren oder ablehnen | ja |
| Verpixeln von Hand markierter Bereiche | ja (GD) | ja |
| Zeitraffer | nein (nächtlicher Befehl wird übersprungen) | ja |

Im einfachen Modus ist die Sammelfreigabe „Freigeben (ohne Gesichter)“ ausgeblendet, weil nichts geprüft wurde. Queue: `PrepareImage` → `MarkForReview`.

Später einschalten: Worker aufsetzen (siehe unten), `CV_WORKER_ENABLED=true`, `CV_WORKER_URL` und `CV_WORKER_TOKEN` setzen. Neue Uploads laufen dann durch die volle Kette; die Zeitraffer für Stationen mit veröffentlichten Fotos holt der nächste nächtliche Lauf nach (oder sofort: `php artisan fotostation:render-changed`). Bereits veröffentlichte Fotos sind nur zugeschnitten, nicht ausgerichtet.

## Ablauf

1. `GET /s/{station}`: Upload-Seite (Ziel des QR-Codes)
2. `POST /s/{station}`: Honeypot, Friendly Captcha, Rate-Limit (3 pro Stunde und IP, 500 pro Tag und Station), Validierung. Das Bild wird **synchron neu kodiert**, alle Metadaten entfallen, nur das Aufnahmedatum bleibt. Gespeichert wird erst diese Fassung, zusammen mit SHA-256, Perceptual Hash und einer Vorschau.
3. Queue `uploads` (konfigurierbar über `FOTOSTATION_QUEUE`), mit Worker: `MatchReference` → `AlignImage` → `DetectFaces` → `MarkForReview`. Jeder Job bleibt unter 90 Sekunden (Grenze der Flex-Worker bei Laravel Cloud Managed Queues).
   - Passt das Foto nicht zum Referenzbild der Station, wird es `auto_rejected` (Stichprobe im Admin, Löschung nach 7 Tagen).
4. Moderation in Filament (`/admin`): Freigeben (mit optionalen zusätzlichen Verpixel-Bereichen) oder Ablehnen (Dateien werden sofort gelöscht).
5. `PublishSubmission`: Gesichter und markierte Bereiche verpixeln, öffentliche Fassung und Thumbnail schreiben, Originale löschen.
6. Nachts um 3:00 Uhr, alles gebündelt (die Datenbank wacht nur einmal auf): Zeitraffer neu rendern (nur bei Änderungen, **synchron im Befehl**, nicht in der Queue), Löschfristen (`model:prune`), IP-Hashes löschen, Fehler melden.

Abweichung vom Architektur-Doc: Den früheren Job `ProcessUpload` (Hashes, Duplikate, Thumbnail) gibt es nicht mehr, das passiert direkt im Request zusammen mit dem Neu-Kodieren. Dadurch landet nie eine Datei mit GPS-Daten im Speicher. Die Gesichtserkennung läuft nach der Ausrichtung, damit die Boxen im ausgerichteten Bild liegen.

## Wichtig: Was ungetestet ist

Diese Anwendung wurde in einer Umgebung ohne Zugriff auf Packagist geschrieben. Deshalb:

- **PHP:** Syntax aller Dateien geprüft, Blade-Templates kompiliert, API-Aufrufe mit dem Quelltext von Laravel 13.5 und Filament 5.9 abgeglichen. **Die PHPUnit-Tests (`tests/`) liefen noch nie.** Bitte zuerst `php artisan test` ausführen und Fehler beheben.
- **cv-worker:** vollständig getestet (20 Tests mit synthetischen Bildern, inkl. Abgleich, Ausrichtung, Verpixeln, Zeitraffer, Auth, vorsignierten S3-URLs). Nicht getestet ist die Gesichtserkennung, weil das YuNet-Modell hier nicht herunterladbar war; das Dockerfile lädt es beim Build.

## Lokale Entwicklung

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
6. **Umgebungsvariablen** (siehe `.env.example`): `APP_URL`, `APP_LOCALE=de`, `SESSION_DRIVER=cookie`, `CACHE_STORE=database`, `CV_WORKER_ENABLED` (zunächst `false`; erst mit Worker `true` plus `CV_WORKER_URL`, `CV_WORKER_TOKEN`, `CV_WORKER_TIMEOUT=60`), `FRIENDLY_CAPTCHA_*`, `FOTOSTATION_ADMIN_EMAIL`, Mail-Zugang. Die `PHOTOS_*`-Variablen nicht setzen, sie gelten nur lokal.
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
3. Ein paar Testfotos hochladen und unter „Einreichungen“ die Inlier-Werte ansehen. `Mindest-Inlier` so einstellen, dass echte Fotos sicher darüber liegen (Startwert 40). Fremde Bilder liegen in Tests meist unter 15.
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
