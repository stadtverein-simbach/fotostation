<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Speicher
    |--------------------------------------------------------------------------
    |
    | Zwei Disks, weil ein Laravel-Cloud-Bucket nur eine Sichtbarkeit kennt.
    | In Laravel Cloud werden die Disks beim Anhängen der Buckets automatisch
    | konfiguriert (Disk-Namen exakt so vergeben). Lokal siehe
    | config/filesystems.php (MinIO aus docker-compose.dev.yml).
    |
    */

    'disks' => [
        'private' => env('FOTOSTATION_PRIVATE_DISK', 'photos-private'),
        'public' => env('FOTOSTATION_PUBLIC_DISK', 'photos-public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Alle Verarbeitungsschritte laufen auf einer Queue. In Laravel Cloud eine
    | Managed Queue mit genau diesem Namen anlegen (Starter-Plan: eine Queue
    | pro Umgebung). Der Zeitraffer läuft nicht in der Queue, sondern synchron
    | im nächtlichen Befehl fotostation:render-changed.
    |
    */

    'queue' => env('FOTOSTATION_QUEUE', 'uploads'),

    /*
    |--------------------------------------------------------------------------
    | Upload
    |--------------------------------------------------------------------------
    */

    'upload' => [
        'max_kb' => (int) env('FOTOSTATION_UPLOAD_MAX_KB', 15360),
        'min_edge' => (int) env('FOTOSTATION_UPLOAD_MIN_EDGE', 1000),
        'max_edge' => (int) env('FOTOSTATION_UPLOAD_MAX_EDGE', 3000),
        'jpeg_quality' => 88,
        'thumb_width' => 480,
        'per_ip_per_hour' => (int) env('FOTOSTATION_UPLOADS_PER_IP_HOUR', 3),
        'per_station_per_day' => (int) env('FOTOSTATION_UPLOADS_PER_STATION_DAY', 500),
        // Duplikate: gleicher SHA-256 immer; zusätzlich gleicher IP-Hash innerhalb
        // von 24 h mit Perceptual-Hash-Abstand bis zu diesem Wert (0 bis 64).
        // Nicht global per Hash vergleichen: Fotos derselben Station ähneln sich
        // naturgemäß stark.
        'duplicate_distance' => 3,
        // Enthält das Foto eine GPS-Position, speichern wir nur den Abstand zur
        // Station (auf 10 m gerundet, höchstens gps_max_distance), nie die
        // Koordinaten. Bis gps_near_distance gilt das Foto als „an der Station“.
        'gps_near_distance' => (int) env('FOTOSTATION_GPS_NEAR_DISTANCE', 200),
        'gps_max_distance' => 1000,
        // Brennweite (Kleinbild-Äquivalent) der Hauptkamera üblicher Handys.
        // Deutlich kürzer = Ultraweitwinkel, deutlich länger = Tele/Zoom.
        'main_focal_length' => [20, 35],
    ],

    /*
    |--------------------------------------------------------------------------
    | Aufbewahrung
    |--------------------------------------------------------------------------
    |
    | Der Aufräum-Job läuft einmal nachts. IP-Hashes älter als 48 h werden
    | dabei gelöscht, liegen also höchstens 72 h vor.
    |
    */

    'retention' => [
        'ip_hash_hours' => 48,
        'auto_rejected_days' => 7,
        'failed_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ausrichtung
    |--------------------------------------------------------------------------
    |
    | Der Worker schneidet je Station einen Sicherheitsrand ab und ergänzt
    | dann noch fehlende Ränder aus dem Referenzbild. Liegt der Anteil echter
    | Fotopixel darunter, wird das Foto in der Moderation markiert und nicht
    | über die Sammelfreigabe veröffentlicht.
    |
    */

    'alignment' => [
        'min_coverage' => (float) env('FOTOSTATION_MIN_COVERAGE', 0.85),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bildverarbeitungs-Worker (Python/OpenCV, eigener Server)
    |--------------------------------------------------------------------------
    |
    | Ohne Worker (CV_WORKER_ENABLED=false) läuft ein einfacher Modus nur mit
    | PHP/GD: kein Abgleich mit dem Referenzbild (keine automatische
    | Ablehnung), keine Ausrichtung, keine Gesichtserkennung und kein
    | Zeitraffer. Fotos werden nur auf das Stationsformat zugeschnitten; beim
    | Veröffentlichen werden ausschließlich von Hand markierte Bereiche
    | verpixelt. Wird der Worker später eingeschaltet, holt der nächtliche
    | Befehl die Zeitraffer aller geänderten Stationen nach.
    |
    */

    'worker' => [
        'enabled' => (bool) env('CV_WORKER_ENABLED', false),
        'url' => rtrim((string) env('CV_WORKER_URL', 'http://localhost:8000'), '/'),
        'token' => env('CV_WORKER_TOKEN'),
        // Muss unter der Laufzeitgrenze der Queue-Worker bleiben
        // (Laravel Cloud Managed Queues, Flex: 90 Sekunden pro Job).
        'timeout' => (int) env('CV_WORKER_TIMEOUT', 60),
        // Der Zeitraffer läuft synchron im nächtlichen Befehl, nicht in der Queue.
        'render_timeout' => (int) env('CV_WORKER_RENDER_TIMEOUT', 900),
        // Gültigkeit der vorsignierten Bucket-URLs für den Worker.
        'url_ttl_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Zeitraffer
    |--------------------------------------------------------------------------
    */

    'render' => [
        'fps' => (int) env('FOTOSTATION_RENDER_FPS', 4),
        'max_frames' => (int) env('FOTOSTATION_RENDER_MAX_FRAMES', 1500),
        'at' => env('FOTOSTATION_NIGHTLY_AT', '03:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spamschutz: ALTCHA (selbst gehostet, Proof of Work)
    |--------------------------------------------------------------------------
    |
    | Ohne ALTCHA_HMAC_KEY wird der Schlüssel aus APP_KEY abgeleitet.
    | ALTCHA_COST steuert die Rechenzeit im Browser (höher = langsamer für
    | Bots, aber auch für ältere Handys).
    |
    */

    'captcha' => [
        'enabled' => (bool) env('ALTCHA_ENABLED', true),
        'hmac_key' => env('ALTCHA_HMAC_KEY'),
        'cost' => (int) env('ALTCHA_COST', 5000),
        'expires' => (int) env('ALTCHA_EXPIRES', 1800), // Sekunden
    ],

    /*
    |--------------------------------------------------------------------------
    | Benachrichtigungen
    |--------------------------------------------------------------------------
    */

    'admin_email' => env('FOTOSTATION_ADMIN_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Betreiber (für Impressum, Schild, Bilderreihen)
    |--------------------------------------------------------------------------
    */

    'operator' => [
        'name' => env('FOTOSTATION_OPERATOR', 'Stadtverein Simbach am Inn e.V.'),
        'series_credit' => env('FOTOSTATION_SERIES_CREDIT', 'Fotostation Stadtverein Simbach am Inn e.V. und Mitwirkende'),
        'series_license' => 'cc-by-4.0',
        'upload_license' => 'cc0-1.0',
    ],

];
