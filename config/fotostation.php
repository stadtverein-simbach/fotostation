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
    | Bildverarbeitungs-Worker (Python/OpenCV, eigener Server)
    |--------------------------------------------------------------------------
    */

    'worker' => [
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
    | Friendly Captcha (v2)
    |--------------------------------------------------------------------------
    */

    'captcha' => [
        'enabled' => (bool) env('FRIENDLY_CAPTCHA_ENABLED', true),
        'sitekey' => env('FRIENDLY_CAPTCHA_SITEKEY'),
        'api_key' => env('FRIENDLY_CAPTCHA_API_KEY'),
        'endpoint' => env('FRIENDLY_CAPTCHA_ENDPOINT', 'eu'), // "eu" oder "global"
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
