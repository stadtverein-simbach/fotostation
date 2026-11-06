<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Filament\Resources\Submissions\SubmissionActions;
use App\Jobs\AlignImage;
use App\Jobs\DetectFaces;
use App\Jobs\MatchReference;
use App\Jobs\PublishSubmission;
use App\Jobs\RenderSeries;
use App\Models\Station;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['photos-private', 'photos-public'] as $disk) {
            $fake = Storage::fake($disk);
            $fake->buildTemporaryUrlsUsing(fn (string $path) => "https://{$disk}.test/{$path}?signed");
            $fake->buildTemporaryUploadUrlsUsing(fn (string $path) => [
                'url' => "https://{$disk}.test/{$path}?signed-put",
                'headers' => ['Content-Type' => ['image/jpeg']],
            ]);
        }
    }

    private const IDENTITY = [[1, 0, 0], [0, 1, 0], [0, 0, 1]];

    public function test_matching_photo_keeps_processing(): void
    {
        Http::fake(['worker.test/match' => Http::response(['inliers' => 900, 'score' => 0.8, 'homography' => self::IDENTITY])]);
        $submission = Submission::factory()->create();

        (new MatchReference($submission))->handle();

        $submission->refresh();
        $this->assertSame(SubmissionStatus::Processing, $submission->status);
        $this->assertSame(900, $submission->match_inliers);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-token')
            && str_contains($request['image_url'], $submission->original_path)
            && str_contains($request['reference_url'], 'references/test.jpg'));
    }

    public function test_unrelated_photo_is_auto_rejected_and_chain_stops(): void
    {
        Http::fake(['worker.test/match' => Http::response(['inliers' => 8, 'score' => 0.02, 'homography' => null])]);
        $submission = Submission::factory()->create();

        (new MatchReference($submission))->handle();
        (new AlignImage($submission))->handle();

        $this->assertSame(SubmissionStatus::AutoRejected, $submission->refresh()->status);
        Http::assertSentCount(1);
    }

    public function test_align_and_faces_store_results(): void
    {
        Http::fake([
            'worker.test/align' => Http::response(['width' => 1600, 'height' => 1200]),
            'worker.test/faces' => Http::response(['boxes' => [['x' => 10, 'y' => 20, 'w' => 30, 'h' => 40, 'score' => 0.9]], 'width' => 1600, 'height' => 1200]),
        ]);
        $submission = Submission::factory()->status(SubmissionStatus::Processing)->create(['homography' => self::IDENTITY]);

        (new AlignImage($submission))->handle();
        (new DetectFaces($submission))->handle();

        $submission->refresh();
        $this->assertSame($submission->pathFor('aligned'), $submission->aligned_path);
        $this->assertSame([['x' => 10, 'y' => 20, 'w' => 30, 'h' => 40]], $submission->face_boxes);
    }

    public function test_publish_blurs_boxes_and_deletes_private_files(): void
    {
        Http::fake(['worker.test/blur' => Http::response(['boxes' => 2, 'width' => 1600, 'height' => 1200])]);

        $submission = Submission::factory()->status(SubmissionStatus::Approved)->create([
            'face_boxes' => [['x' => 10, 'y' => 20, 'w' => 30, 'h' => 40]],
            'manual_boxes' => [['x' => 50, 'y' => 50, 'w' => 10, 'h' => 5]],
        ]);
        $submission->forceFill(['aligned_path' => $submission->pathFor('aligned')])->save();
        Storage::disk('photos-private')->put($submission->original_path, 'x');
        Storage::disk('photos-private')->put($submission->aligned_path, 'x');

        (new PublishSubmission($submission))->handle();

        $submission->refresh();
        $this->assertSame(SubmissionStatus::Published, $submission->status);
        $this->assertNotNull($submission->public_path);
        $this->assertNull($submission->original_path);
        Storage::disk('photos-private')->assertMissing($submission->pathFor('original'));
        $this->assertTrue($submission->station->refresh()->needs_render);

        Http::assertSent(function (Request $request) {
            // Manuelle Box 50 %/50 % von 1600x1200 => 800/600
            return $request['boxes'][1] === ['x' => 800, 'y' => 600, 'w' => 160, 'h' => 60];
        });
    }

    public function test_reject_deletes_files_immediately(): void
    {
        $submission = Submission::factory()->status(SubmissionStatus::PendingReview)->create();
        Storage::disk('photos-private')->put($submission->original_path, 'x');

        SubmissionActions::markRejected($submission, 'Personen erkennbar');

        $this->assertSame(SubmissionStatus::Rejected, $submission->refresh()->status);
        Storage::disk('photos-private')->assertMissing($submission->pathFor('original'));
    }

    public function test_render_series_creates_video_and_resets_flag(): void
    {
        Http::fake(['worker.test/render' => Http::response(['frames' => 3, 'duration' => 0.75])]);

        $station = Station::factory()->create(['needs_render' => true]);
        foreach (range(1, 3) as $day) {
            $submission = Submission::factory()->for($station)->status(SubmissionStatus::Published)->create([
                'taken_at' => now()->subDays(10 - $day),
            ]);
            $submission->forceFill(['public_path' => $submission->pathFor('public')])->save();
        }

        (new RenderSeries($station))->handle(app(\App\Services\CvWorker::class), app(\App\Services\PhotoStorage::class));

        $series = $station->series()->sole();
        $this->assertSame(3, $series->frame_count);
        $this->assertNotNull($series->rendered_at);
        $this->assertCount(3, $series->frames);
        $this->assertFalse($station->refresh()->needs_render);
    }

    public function test_prune_removes_old_auto_rejected_and_rejected(): void
    {
        $old = Submission::factory()->status(SubmissionStatus::AutoRejected)->create();
        Submission::whereKey($old->id)->update(['updated_at' => now()->subDays(8)]);
        $fresh = Submission::factory()->status(SubmissionStatus::AutoRejected)->create();
        $rejected = Submission::factory()->status(SubmissionStatus::Rejected)->create();
        $published = Submission::factory()->status(SubmissionStatus::Published)->create();

        $this->artisan('model:prune', ['--model' => [Submission::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelMissing($rejected);
        $this->assertModelExists($fresh);
        $this->assertModelExists($published);
    }

    public function test_ip_hashes_are_purged_after_48_hours(): void
    {
        $old = Submission::factory()->create();
        Submission::whereKey($old->id)->update(['created_at' => now()->subHours(49)]);
        $new = Submission::factory()->create();

        $this->artisan('fotostation:purge-ip-hashes')->assertSuccessful();

        $this->assertNull($old->refresh()->ip_hash);
        $this->assertNotNull($new->refresh()->ip_hash);
    }

    public function test_render_command_runs_synchronously_for_changed_stations(): void
    {
        Http::fake(['worker.test/render' => Http::response(['frames' => 2, 'duration' => 0.5])]);

        $changed = Station::factory()->create(['needs_render' => true]);
        $unchanged = Station::factory()->create(['needs_render' => false]);
        foreach ([$changed, $unchanged] as $station) {
            foreach (range(1, 2) as $i) {
                $submission = Submission::factory()->for($station)->status(SubmissionStatus::Published)->create();
                $submission->forceFill(['public_path' => $submission->pathFor('public')])->save();
            }
        }

        $this->artisan('fotostation:render-changed')->assertSuccessful();

        $this->assertSame(1, $changed->series()->count());
        $this->assertSame(0, $unchanged->series()->count());
        Http::assertSentCount(1);
    }

    public function test_create_admin_works_non_interactively(): void
    {
        $this->artisan('fotostation:create-admin', [
            'email' => 'admin@example.de',
            'name' => 'Admin',
            '--password' => 'ein-sehr-langes-passwort',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertTrue(\App\Models\User::where('email', 'admin@example.de')->sole()->isAdmin());
    }
}
