<?php

namespace Database\Factories;

use App\Enums\SubmissionStatus;
use App\Models\ConsentVersion;
use App\Models\Station;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'status' => SubmissionStatus::Received,
            'consent_version_id' => fn () => ConsentVersion::current()?->id ?? ConsentVersion::create([
                'version' => 'test',
                'license' => 'cc0-1.0',
                'text' => 'Test',
                'valid_from' => now()->subDay(),
            ])->id,
            'consent_at' => now(),
            'sha256' => hash('sha256', fake()->uuid()),
            'phash' => bin2hex(random_bytes(8)),
            'ip_hash' => hash('sha256', fake()->ipv4()),
            'width' => 3000,
            'height' => 2250,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Submission $submission): void {
            $submission->id ??= $submission->newUniqueId();
            $submission->original_path ??= $submission->pathFor('original');
        });
    }

    public function status(SubmissionStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
