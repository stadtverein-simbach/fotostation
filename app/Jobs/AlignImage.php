<?php

namespace App\Jobs;

use App\Services\CvWorker;

/**
 * Entzerrt das Foto auf die Perspektive des Referenzbilds und bringt es
 * auf die Zielgröße der Station. Erst dadurch passen alle Bilder einer
 * Reihe pixelgenau übereinander. Der Worker schneidet dabei den
 * Sicherheitsrand der Station ab und ergänzt fehlende Ränder aus dem
 * Referenzbild (statt Randpixel zu Streifen zu ziehen).
 */
class AlignImage extends SubmissionJob
{
    protected function process(): void
    {
        $submission = $this->submission;
        $station = $submission->station;
        $path = $submission->pathFor('aligned');

        $result = app(CvWorker::class)->align(
            imagePath: $submission->original_path,
            referencePath: $station->reference_path,
            homography: $submission->homography,
            width: $station->output_width,
            height: $station->output_height,
            targetPath: $path,
            margin: $station->cropMarginFraction(),
        );

        $submission->forceFill([
            'aligned_path' => $path,
            'coverage' => isset($result['coverage']) ? (float) $result['coverage'] : null,
        ])->save();
    }
}
