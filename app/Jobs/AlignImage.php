<?php

namespace App\Jobs;

use App\Services\CvWorker;

/**
 * Entzerrt das Foto auf die Perspektive des Referenzbilds und bringt es
 * auf die Zielgröße der Station. Erst dadurch passen alle Bilder einer
 * Reihe pixelgenau übereinander.
 */
class AlignImage extends SubmissionJob
{
    protected function process(): void
    {
        $submission = $this->submission;
        $station = $submission->station;
        $path = $submission->pathFor('aligned');

        app(CvWorker::class)->align(
            imagePath: $submission->original_path,
            referencePath: $station->reference_path,
            homography: $submission->homography,
            width: $station->output_width,
            height: $station->output_height,
            targetPath: $path,
        );

        $submission->forceFill(['aligned_path' => $path])->save();
    }
}
