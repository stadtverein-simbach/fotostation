<?php

namespace App\Jobs;

use App\Enums\SubmissionStatus;
use App\Services\CvWorker;

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

        app(CvWorker::class)->publish(
            alignedPath: $submission->aligned_path,
            boxes: $boxes,
            publicPath: $publicPath,
            thumbPath: $thumbPath,
            thumbWidth: (int) config('fotostation.upload.thumb_width'),
        );

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
