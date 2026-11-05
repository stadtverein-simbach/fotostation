<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Zugriff auf die beiden Foto-Buckets.
 *
 * Laravel Cloud konfiguriert seine Disks mit 'throw' => false, deshalb
 * wird der Rückgabewert von put() hier geprüft. Für den Worker werden
 * kurzlebige, vorsignierte URLs erzeugt: Er bekommt nie Zugangsdaten.
 */
class PhotoStorage
{
    public function private(): FilesystemAdapter
    {
        return Storage::disk(config('fotostation.disks.private'));
    }

    public function public(): FilesystemAdapter
    {
        return Storage::disk(config('fotostation.disks.public'));
    }

    public function putPrivate(string $path, string $contents, string $contentType): void
    {
        $this->put($this->private(), $path, $contents, $contentType);
    }

    public function putPublic(string $path, string $contents, string $contentType): void
    {
        $this->put($this->public(), $path, $contents, $contentType);
    }

    /**
     * Lese-URL für den Worker.
     */
    public function readUrl(string $path, bool $public = false): string
    {
        if ($public) {
            return $this->public()->url($path);
        }

        return $this->private()->temporaryUrl($path, now()->addMinutes($this->ttl()));
    }

    /**
     * Schreib-Ziel für den Worker: URL plus Header, die beim PUT mitzusenden sind.
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public function uploadTarget(string $path, string $contentType, bool $public = false): array
    {
        $disk = $public ? $this->public() : $this->private();

        $target = $disk->temporaryUploadUrl($path, now()->addMinutes($this->ttl()), [
            'ContentType' => $contentType,
        ]);

        $headers = [];
        foreach ($target['headers'] as $name => $values) {
            if (strtolower($name) === 'host') {
                continue;
            }
            $headers[$name] = is_array($values) ? implode(', ', $values) : (string) $values;
        }

        return ['url' => $target['url'], 'headers' => $headers];
    }

    private function put(Filesystem $disk, string $path, string $contents, string $contentType): void
    {
        $ok = $disk->put($path, $contents, ['ContentType' => $contentType]);

        if ($ok === false) {
            throw new RuntimeException("Datei konnte nicht gespeichert werden: {$path}");
        }
    }

    private function ttl(): int
    {
        return (int) config('fotostation.worker.url_ttl_minutes', 15);
    }
}
