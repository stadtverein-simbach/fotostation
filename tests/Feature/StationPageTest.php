<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Models\Station;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Optionale Anzeige von Zeitraffer und Vorher/Nachher-Schieber.
 */
class StationPageTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('photos-private');
        Storage::fake('photos-public');

        $this->station = Station::factory()->create();
        foreach ([1, 2] as $day) {
            $submission = Submission::factory()->for($this->station)->status(SubmissionStatus::Published)->create([
                'taken_at' => now()->subDays(10 - $day),
            ]);
            $submission->forceFill(['public_path' => $submission->pathFor('public')])->save();
        }
        $this->station->series()->create([
            'video_path' => "{$this->station->id}/series/1.mp4",
            'frame_count' => 2,
            'rendered_at' => now(),
        ]);
    }

    private function thanksUrl(): string
    {
        return URL::temporarySignedRoute('upload.thanks', now()->addMinutes(30), $this->station);
    }

    public function test_timelapse_and_comparison_are_hidden_by_default(): void
    {
        $this->get(route('stations.show', $this->station))
            ->assertOk()
            ->assertDontSee('<video', false)
            ->assertDontSee('Vorher und nachher');

        $this->get($this->thanksUrl())
            ->assertOk()
            ->assertDontSee('<video', false)
            ->assertDontSee('Bilderreihe');
    }

    public function test_timelapse_is_shown_when_enabled(): void
    {
        config(['fotostation.display.timelapse' => true]);

        $this->get(route('stations.show', $this->station))
            ->assertOk()
            ->assertSee('<video', false)
            ->assertDontSee('Vorher und nachher');

        $this->get($this->thanksUrl())
            ->assertOk()
            ->assertSee('<video', false)
            ->assertSee('Bilderreihe');
    }

    public function test_comparison_is_shown_when_enabled(): void
    {
        config(['fotostation.display.comparison' => true]);

        $this->get(route('stations.show', $this->station))
            ->assertOk()
            ->assertSee('Vorher und nachher')
            ->assertDontSee('<video', false);
    }

    public function test_photos_open_in_lightbox(): void
    {
        $this->get(route('stations.show', $this->station))
            ->assertOk()
            ->assertSee('data-lightbox-dialog', false)
            ->assertSee('data-lightbox', false);
    }
}
