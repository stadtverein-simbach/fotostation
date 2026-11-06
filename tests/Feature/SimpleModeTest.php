<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Jobs\MarkForReview;
use App\Jobs\PrepareImage;
use App\Jobs\PublishSubmission;
use App\Models\Station;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Betrieb ohne Bildverarbeitungs-Worker (CV_WORKER_ENABLED=false).
 */
class SimpleModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fotostation.worker.enabled' => false]);
        Storage::fake('photos-private');
        Storage::fake('photos-public');
        Http::fake();
        Http::preventStrayRequests();
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x += 20) {
            imagefilledrectangle($image, $x, 0, $x + 9, $height, imagecolorallocate($image, 255, 255, 255));
        }
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    public function test_upload_queues_simple_chain(): void
    {
        Bus::fake();
        $station = Station::factory()->create();
        $submission = Submission::factory()->for($station)->create();

        \App\Support\Pipeline::dispatch($submission);

        Bus::assertChained([PrepareImage::class, MarkForReview::class]);
    }

    public function test_prepare_crops_to_station_format_and_marks_for_review(): void
    {
        $station = Station::factory()->create(['output_width' => 800, 'output_height' => 600]);
        $submission = Submission::factory()->for($station)->create();
        Storage::disk('photos-private')->put($submission->original_path, $this->jpeg(1200, 1600));

        (new PrepareImage($submission))->handle();
        (new MarkForReview($submission))->handle();

        $submission->refresh();
        $this->assertSame(SubmissionStatus::PendingReview, $submission->status);
        $this->assertSame($submission->pathFor('aligned'), $submission->aligned_path);

        [$w, $h] = getimagesizefromstring(Storage::disk('photos-private')->get($submission->aligned_path));
        $this->assertSame([800, 600], [$w, $h]);
        Http::assertNothingSent();
    }

    public function test_publish_pixelates_manual_boxes_without_worker(): void
    {
        $station = Station::factory()->create(['output_width' => 800, 'output_height' => 600]);
        $submission = Submission::factory()->for($station)->status(SubmissionStatus::Approved)->create([
            'manual_boxes' => [['x' => 0, 'y' => 0, 'w' => 50, 'h' => 50]],
        ]);
        $submission->forceFill(['aligned_path' => $submission->pathFor('aligned')])->save();
        Storage::disk('photos-private')->put($submission->original_path, 'x');
        Storage::disk('photos-private')->put($submission->aligned_path, $this->jpeg(800, 600));

        (new PublishSubmission($submission))->handle();

        $submission->refresh();
        $this->assertSame(SubmissionStatus::Published, $submission->status);
        Storage::disk('photos-public')->assertExists($submission->public_path);
        Storage::disk('photos-public')->assertExists($submission->thumb_path);
        Storage::disk('photos-private')->assertMissing($submission->pathFor('aligned'));

        // Im verpixelten Bereich sind die 10-px-Streifen verschwunden, außerhalb nicht.
        $image = imagecreatefromstring(Storage::disk('photos-public')->get($submission->public_path));
        $contrast = function (int $fromX, int $y) use ($image): int {
            $values = [];
            for ($x = $fromX; $x < $fromX + 40; $x++) {
                $values[] = imagecolorat($image, $x, $y) & 0xFF;
            }

            return max($values) - min($values);
        };
        $this->assertLessThan(120, $contrast(100, 100));
        $this->assertGreaterThan(200, $contrast(500, 400));
        Http::assertNothingSent();
    }

    public function test_render_command_is_skipped_and_flag_kept(): void
    {
        $station = Station::factory()->create(['needs_render' => true]);

        $this->artisan('fotostation:render-changed')->assertSuccessful();

        $this->assertTrue($station->refresh()->needs_render);
        $this->assertSame(0, $station->series()->count());
        Http::assertNothingSent();
    }
}
