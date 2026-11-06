<?php

namespace App\Jobs;

use App\Enums\SubmissionStatus;
use App\Services\PhotoStorage;
use App\Support\SimpleImageEditor;
use RuntimeException;

/**
 * Einfacher Modus ohne Worker: schneidet das Foto mittig auf das
 * Stationsformat zu. Die Fassung ersetzt die Ausrichtung, damit von Hand
 * markierte Bereiche in Moderation und Veröffentlichung übereinstimmen und
 * ein späterer Zeitraffer einheitliche Bildgrößen hat.
 */
class PrepareImage extends SubmissionJob
{
    protected function process(): void
    {
        $submission = $this->submission;
        $station = $submission->station;
        $storage = app(PhotoStorage::class);

        $submission->transitionTo(SubmissionStatus::Processing);

        $original = $storage->private()->get($submission->original_path);

        if ($original === null) {
            throw new RuntimeException("Original fehlt: {$submission->original_path}");
        }

        $path = $submission->pathFor('aligned');
        $jpeg = app(SimpleImageEditor::class)->cover($original, $station->output_width, $station->output_height);
        $storage->putPrivate($path, $jpeg, 'image/jpeg');

        $submission->forceFill(['aligned_path' => $path])->save();
    }
}
