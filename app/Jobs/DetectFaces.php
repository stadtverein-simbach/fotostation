<?php

namespace App\Jobs;

use App\Services\CvWorker;

/**
 * Sucht Gesichter im ausgerichteten Bild. Die Boxen werden in der
 * Moderation angezeigt und beim Veröffentlichen verpixelt.
 */
class DetectFaces extends SubmissionJob
{
    protected function process(): void
    {
        $submission = $this->submission;

        $result = app(CvWorker::class)->faces($submission->aligned_path);

        $boxes = collect($result['boxes'] ?? [])
            ->map(fn (array $box) => [
                'x' => (int) $box['x'],
                'y' => (int) $box['y'],
                'w' => (int) $box['w'],
                'h' => (int) $box['h'],
            ])
            ->values()
            ->all();

        $submission->forceFill(['face_boxes' => $boxes])->save();
    }
}
