<?php

namespace App\Jobs;

use App\Enums\SubmissionStatus;
use App\Services\CvWorker;
use App\Services\PhotoStorage;
use App\Support\Pipeline;
use App\Support\SimpleImageEditor;
use RuntimeException;

/**
 * Nach der Freigabe: Gesichter und von Hand markierte Bereiche verpixeln,
 * öffentliche Fassung und Thumbnail schreiben, Originale löschen.
 *
 * Schlägt der Job fehl, bleibt die Einreichung im Status „Freigegeben“ und
 * kann in der Moderation erneut veröffentlicht werden.
 */
class PublishSubmission extends SubmissionJob
{
    protected function shouldRun(): bool
    {
        return $this->submission->status === SubmissionStatus::Approved;
    }

    protected function process(): void
    {
        $submission = $this->submission;
        $station = $submission->station;

        $boxes = [
            ...($submission->face_boxes ?? []),
            ...$this->manualBoxesInPixels($station->output_width, $station->output_height),
        ];

        $publicPath = $submission->pathFor('public');
        $thumbPath = $submission->pathFor('thumb');

        $thumbWidth = (int) config('fotostation.upload.thumb_width');

        if (Pipeline::workerEnabled()) {
            app(CvWorker::class)->publish(
                alignedPath: $submission->aligned_path,
                boxes: $boxes,
                publicPath: $publicPath,
                thumbPath: $thumbPath,
                thumbWidth: $thumbWidth,
            );
        } else {
            $this->publishLocally($boxes, $publicPath, $thumbPath, $thumbWidth);
        }

        $submission->transitionTo(SubmissionStatus::Published, [
            'public_path' => $publicPath,
            'thumb_path' => $thumbPath,
            'published_at' => now(),
        ]);

        // Unbearbeitete Fassungen werden nicht mehr gebraucht (Datenminimierung).
        $submission->deletePrivateFiles();

        $station->forceFill(['needs_render' => true])->save();
    }

    /**
     * Einfacher Modus ohne Worker: Verpixeln und Thumbnail mit GD.
     */
    private function publishLocally(array $boxes, string $publicPath, string $thumbPath, int $thumbWidth): void
    {
        $storage = app(PhotoStorage::class);
        $source = $this->submission->aligned_path ?? $this->submission->original_path;
        $contents = $source ? $storage->private()->get($source) : null;

        if ($contents === null) {
            throw new RuntimeException('Keine Bilddatei zum Veröffentlichen vorhanden.');
        }

        $result = app(SimpleImageEditor::class)->publish($contents, $boxes, $thumbWidth);

        $storage->putPublic($publicPath, $result['jpeg'], 'image/jpeg');
        $storage->putPublic($thumbPath, $result['thumb'], 'image/webp');
    }

    /**
     * Von Hand gesetzte Boxen sind in Prozent des ausgerichteten Bilds gespeichert.
     *
     * @return array<int, array{x: int, y: int, w: int, h: int}>
     */
    private function manualBoxesInPixels(int $width, int $height): array
    {
        return collect($this->submission->manual_boxes ?? [])
            ->map(fn (array $box) => [
                'x' => (int) round($width * (float) $box['x'] / 100),
                'y' => (int) round($height * (float) $box['y'] / 100),
                'w' => (int) round($width * (float) $box['w'] / 100),
                'h' => (int) round($height * (float) $box['h'] / 100),
            ])
            ->filter(fn (array $box) => $box['w'] > 0 && $box['h'] > 0)
            ->values()
            ->all();
    }
}
