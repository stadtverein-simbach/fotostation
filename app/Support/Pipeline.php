<?php

namespace App\Support;

use App\Jobs\AlignImage;
use App\Jobs\DetectFaces;
use App\Jobs\MarkForReview;
use App\Jobs\MatchReference;
use App\Jobs\PrepareImage;
use App\Models\Submission;
use Illuminate\Support\Facades\Bus;

/**
 * Stellt die Verarbeitungskette einer Einreichung zusammen, abhängig davon,
 * ob der Bildverarbeitungs-Worker eingeschaltet ist.
 */
class Pipeline
{
    public static function workerEnabled(): bool
    {
        return (bool) config('fotostation.worker.enabled');
    }

    /**
     * Komplette Verarbeitung nach dem Upload bis zur Freigabe.
     */
    public static function dispatch(Submission $submission): void
    {
        $jobs = static::workerEnabled()
            ? [
                new MatchReference($submission),
                new AlignImage($submission),
                new DetectFaces($submission),
                new MarkForReview($submission),
            ]
            : [
                new PrepareImage($submission),
                new MarkForReview($submission),
            ];

        Bus::chain($jobs)->onQueue(config('fotostation.queue'))->dispatch();
    }

    /**
     * Verarbeitung ab der Ausrichtung (Bildabgleich bereits erfolgt).
     */
    public static function dispatchFromAlignment(Submission $submission): void
    {
        if (! static::workerEnabled()) {
            static::dispatch($submission);

            return;
        }

        Bus::chain([
            new AlignImage($submission),
            new DetectFaces($submission),
            new MarkForReview($submission),
        ])->onQueue(config('fotostation.queue'))->dispatch();
    }
}
