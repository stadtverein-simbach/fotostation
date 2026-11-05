<?php

namespace App\Jobs;

use App\Enums\SubmissionStatus;

class MarkForReview extends SubmissionJob
{
    protected function process(): void
    {
        $this->submission->transitionTo(SubmissionStatus::PendingReview);
    }
}
