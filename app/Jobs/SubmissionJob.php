<?php

namespace App\Jobs;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gemeinsame Basis der Verarbeitungsschritte einer Einreichung.
 *
 * Jeder Schritt prüft zu Beginn den Status und bricht ab, wenn die
 * Einreichung inzwischen abgelehnt wurde oder fehlgeschlagen ist. Damit
 * sind die Jobs idempotent und eine abgebrochene Kette bleibt stehen.
 */
abstract class SubmissionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public Submission $submission) {}

    public function handle(): void
    {
        $this->submission->refresh();

        if (! $this->shouldRun()) {
            return;
        }

        $this->process();
    }

    abstract protected function process(): void;

    protected function shouldRun(): bool
    {
        return $this->submission->status->isProcessable();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Verarbeitung einer Einreichung fehlgeschlagen.', [
            'submission' => $this->submission->id,
            'job' => static::class,
            'error' => $exception?->getMessage(),
        ]);

        $this->submission->refresh();

        if ($this->submission->status->isProcessable()) {
            $this->submission->transitionTo(SubmissionStatus::Failed, [
                'error' => class_basename(static::class).': '.mb_substr((string) $exception?->getMessage(), 0, 1000),
            ]);
        }
    }
}
