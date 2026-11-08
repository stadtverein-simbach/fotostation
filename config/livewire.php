<?php

/*
| Fotostation: Nur die Einstellungen, die vom Livewire-Standard abweichen.
| Livewire ergänzt alle übrigen Schlüssel aus seiner eigenen Konfiguration.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Temporäre Datei-Uploads (Referenzbild in Filament)
    |--------------------------------------------------------------------------
    |
    | Ohne eigene Angabe nimmt Livewire die Standard-Disk (FILESYSTEM_DISK).
    | Ist das in Laravel Cloud ein Bucket, lädt der Browser direkt per
    | vorsignierter URL in den Bucket hoch. Das scheitert dort (fehlende
    | Header in der Antwort, CORS am Bucket). Deshalb gehen Uploads immer
    | über die App auf die lokale Disk; Filament verschiebt die Datei beim
    | Speichern in den privaten Bucket.
    |
    | Achtung: Bei mehreren App-Instanzen kann der Zwischenspeicher auf einer
    | anderen Instanz liegen als die Anfrage zum Speichern. Dann eine Disk
    | wählen, die alle Instanzen teilen, und am Bucket CORS für PUT erlauben.
    |
    */

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK', 'local'),
        // Passend zu maxSize(15360) am Referenzbild (Livewire-Standard: 12 MB).
        'rules' => ['required', 'file', 'max:15360'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
