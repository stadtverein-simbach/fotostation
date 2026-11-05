# Fotostation

Passanten fotografieren durch einen festen Rahmen, laden das Foto über einen QR-Code hoch, und aus den freigegebenen Fotos entstehen Bilderreihen und ein nächtlich gerenderter Zeitraffer.

- **Laravel 13 + Filament 5** (dieses Repository), gehostet in **Laravel Cloud**
- **cv-worker** (Python, FastAPI, OpenCV, ffmpeg) in `cv-worker/`, gehostet auf einem **eigenen VPS**

## Ablauf

1. `GET /s/{station}`: Upload-Seite (Ziel des QR-Codes)
2. `POST /s/{station}`: Honeypot, Friendly Captcha, Rate-Limit (3 pro Stunde und IP, 500 pro Tag und Station), Validierung. Das Bild wird **synchron neu kodiert**, alle Metadaten entfallen, nur das Aufnahmedatum bleibt. Gespeichert wird erst diese Fassung, zusammen mit SHA-256, Perceptual Hash und einer Vorschau.
3. Queue `uploads`: `MatchReference` → `AlignImage` → `DetectFaces` → `MarkForReview`
   - Passt das Foto nicht zum Referenzbild der Station, wird es `auto_rejected` (Stichprobe im Admin, Löschung nach 7 Tagen).
4. Moderation in Filament (`/admin`): Freigeben (mit optionalen zusätzlichen Verpixel-Bereichen) oder Ablehnen (Dateien werden sofort gelöscht).
5. `PublishSubmission`: Gesichter und markierte Bereiche verpixeln, öffentliche Fassung und Thumbnail schreiben, Originale löschen.
6. Nachts um 3:00 Uhr, alles gebündelt (die Datenbank wacht nur einmal auf): Zeitraffer neu rendern (nur bei Änderungen), Löschfristen (`model:prune`), IP-Hashes löschen, Fehler melden.

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
docker compose -f docker-compose.dev.yml up -d --build

php artisan migrate
php artisan fotostation:create-admin du@example.de "Dein Name"

# in getrennten Terminals
php artisan serve
php artisan queue:work --queue=uploads,render,default
```

Dann `/admin` öffnen, eine Station anlegen, ein Referenzbild hochladen und unter `/s/{kurzname}` ein Foto hochladen. MinIO-Konsole: http://localhost:9001 (fotostation / fotostation-secret).

Tests:

```bash
php artisan test
cd cv-worker && pip install -r requirements-dev.txt && pytest
```

## Laravel Cloud

1. Repository verbinden, Region **EU (Frankfurt)**, PHP 8.4 oder 8.5.
2. **Datenbank:** Laravel Postgres anhängen (Hibernation aktivieren).
3. **Cache:** Key-Value-Store anhängen (für Rate-Limiter und Cache), oder `CACHE_STORE=database` lassen.
4. **Buckets:** zwei Laravel-Object-Storage-Buckets anlegen und anhängen:
   - `photos-private`: Sichtbarkeit privat, Disk-Name **`photos-private`**
   - `photos-public`: Sichtbarkeit öffentlich, Disk-Name **`photos-public`**
   - Jurisdiktion EU wählen, falls angeboten.
5. **Umgebungsvariablen** (siehe `.env.example`): `APP_LOCALE=de`, `SESSION_DRIVER=cookie`, `CV_WORKER_URL`, `CV_WORKER_TOKEN`, `FRIENDLY_CAPTCHA_*`, `FOTOSTATION_ADMIN_EMAIL`, Mail-Zugang.
6. **Build-Befehle:** `composer install --no-dev`, `npm ci && npm run build`. **Deploy-Befehl:** `php artisan migrate --force`.
7. **Worker-Cluster** mit Queue-Worker für die Queues `uploads,render,default` und aktiviertem **Scheduler**.
8. Nach dem ersten Deployment über „Commands“: `php artisan fotostation:create-admin ...`

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
