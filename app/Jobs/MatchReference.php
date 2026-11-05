<?php

namespace App\Jobs;

use App\Enums\SubmissionStatus;
use App\Services\CvWorker;

/**
 * Vergleicht das Foto mit dem Referenzbild der Station. Fotos, die nicht
 * von der Station stammen (Selfies, Memes, Fotos aus dem Netz), fallen hier
 * heraus: der wirksamste Spamfilter.
 */
class MatchReference extends SubmissionJob
{
    protected function process(): void
    {
        $submission = $this->submission;
        $station = $submission->station;

        $submission->transitionTo(SubmissionStatus::Processing);

        $result = app(CvWorker::class)->match($submission->original_path, $station->reference_path);

        $inliers = (int) ($result['inliers'] ?? 0);
        $attributes = [
            'match_inliers' => $inliers,
            'match_score' => (float) ($result['score'] ?? 0),
            'homography' => $result['homography'] ?? null,
        ];

        if ($inliers < $station->min_inliers || empty($result['homography'])) {
            $submission->transitionTo(SubmissionStatus::AutoRejected, [
                ...$attributes,
                'rejection_reason' => 'Passt nicht zum Referenzbild der Station.',
            ]);

            return;
        }

        $submission->forceFill($attributes)->save();
    }
}
