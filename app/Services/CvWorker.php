<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP-Client für den Bildverarbeitungs-Worker (Python/OpenCV, eigener Server).
 *
 * Der Worker bekommt nur vorsignierte URLs: Er lädt Bilder über Lese-URLs
 * und schreibt Ergebnisse per PUT direkt in den Bucket.
 */
class CvWorker
{
    public function __construct(private PhotoStorage $storage) {}

    /**
     * @return array{inliers: int, score: float, homography: array<int, array<int, float>>|null}
     */
    public function match(string $imagePath, string $referencePath): array
    {
        return $this->client()->post('/match', [
            'image_url' => $this->storage->readUrl($imagePath),
            'reference_url' => $this->storage->readUrl($referencePath),
        ])->throw()->json();
    }

    /**
     * @return array{boxes: array<int, array{x: int, y: int, w: int, h: int, score: float}>, width: int, height: int}
     */
    public function faces(string $imagePath): array
    {
        return $this->client()->post('/faces', [
            'image_url' => $this->storage->readUrl($imagePath),
        ])->throw()->json();
    }

    /**
     * Entzerrt das Bild auf die Referenz und speichert es in der Zielgröße.
     *
     * @param  array<int, array<int, float>>  $homography
     * @return array{width: int, height: int}
     */
    public function align(string $imagePath, string $referencePath, array $homography, int $width, int $height, string $targetPath): array
    {
        return $this->client()->post('/align', [
            'image_url' => $this->storage->readUrl($imagePath),
            'reference_url' => $this->storage->readUrl($referencePath),
            'homography' => $homography,
            'width' => $width,
            'height' => $height,
            'upload' => $this->storage->uploadTarget($targetPath, 'image/jpeg'),
        ])->throw()->json();
    }

    /**
     * Verpixelt Boxen und schreibt öffentliche Version und Thumbnail.
     *
     * @param  array<int, array{x: int|float, y: int|float, w: int|float, h: int|float}>  $boxes  Pixel im ausgerichteten Bild
     */
    public function publish(string $alignedPath, array $boxes, string $publicPath, string $thumbPath, int $thumbWidth): array
    {
        return $this->client()->post('/blur', [
            'image_url' => $this->storage->readUrl($alignedPath),
            'boxes' => array_values($boxes),
            'upload' => $this->storage->uploadTarget($publicPath, 'image/jpeg', public: true),
            'thumb_upload' => $this->storage->uploadTarget($thumbPath, 'image/webp', public: true),
            'thumb_width' => $thumbWidth,
        ])->throw()->json();
    }

    /**
     * @param  array<int, string>  $framePaths  Pfade im öffentlichen Bucket, in Reihenfolge
     * @return array{frames: int, duration: float}
     */
    public function render(array $framePaths, int $fps, int $width, int $height, string $targetPath): array
    {
        return $this->client()
            ->timeout(max(300, (int) config('fotostation.worker.timeout')))
            ->post('/render', [
                'frame_urls' => array_map(fn (string $p) => $this->storage->readUrl($p, public: true), $framePaths),
                'fps' => $fps,
                'width' => $width,
                'height' => $height,
                'upload' => $this->storage->uploadTarget($targetPath, 'video/mp4', public: true),
            ])->throw()->json();
    }

    private function client(): PendingRequest
    {
        $config = config('fotostation.worker');

        return Http::baseUrl($config['url'])
            ->withToken((string) $config['token'])
            ->acceptJson()
            ->asJson()
            ->timeout($config['timeout'])
            ->retry(2, 2000, function (\Throwable $e): bool {
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError());
            }, throw: false);
    }
}
