<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Jobs\MatchReference;
use App\Models\Station;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('photos-private');
        Storage::fake('photos-public');
        Bus::fake();
    }

    private function photo(string $name = 'foto.jpg', int $seed = 1): UploadedFile
    {
        mt_srand($seed);
        $image = imagecreatetruecolor(1600, 1200);
        for ($i = 0; $i < 80; $i++) {
            $color = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledrectangle($image, mt_rand(0, 1500), mt_rand(0, 1100), mt_rand(0, 1600), mt_rand(0, 1200), $color);
        }
        $path = tempnam(sys_get_temp_dir(), 'foto');
        imagejpeg($image, $path, 90);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    public function test_upload_page_is_shown(): void
    {
        $station = Station::factory()->create(['name' => 'Stadtplatz']);

        $this->get("/s/{$station->slug}")
            ->assertOk()
            ->assertSee('Stadtplatz')
            ->assertSee('Foto hochladen');
    }

    public function test_inactive_station_is_not_found(): void
    {
        $station = Station::factory()->create(['is_active' => false]);

        $this->get("/s/{$station->slug}")->assertNotFound();
    }

    public function test_upload_is_stored_without_metadata_and_processing_is_queued(): void
    {
        $station = Station::factory()->create();

        $response = $this->post("/s/{$station->slug}", [
            'photo' => $this->photo(),
            'consent' => '1',
            'name' => 'Anna',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/danke', $response->headers->get('Location'));

        $submission = Submission::sole();
        $this->assertSame(SubmissionStatus::Received, $submission->status);
        $this->assertSame('Anna', $submission->display_name);
        $this->assertNotNull($submission->ip_hash);
        $this->assertNotSame('127.0.0.1', $submission->ip_hash);

        Storage::disk('photos-private')->assertExists($submission->original_path);
        Storage::disk('photos-private')->assertExists($submission->review_thumb_path);

        Bus::assertChained([MatchReference::class, \App\Jobs\AlignImage::class, \App\Jobs\DetectFaces::class, \App\Jobs\MarkForReview::class]);
    }

    public function test_consent_is_required(): void
    {
        $station = Station::factory()->create();

        $this->post("/s/{$station->slug}", ['photo' => $this->photo()])
            ->assertSessionHasErrors('consent');

        $this->assertSame(0, Submission::count());
    }

    public function test_small_images_are_rejected(): void
    {
        $station = Station::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'klein');
        imagejpeg(imagecreatetruecolor(640, 480), $path);

        $this->post("/s/{$station->slug}", [
            'photo' => new UploadedFile($path, 'klein.jpg', 'image/jpeg', null, true),
            'consent' => '1',
        ])->assertSessionHasErrors('photo');
    }

    public function test_exact_duplicates_are_not_stored_twice(): void
    {
        $station = Station::factory()->create();
        $photo = $this->photo();

        $this->post("/s/{$station->slug}", ['photo' => $photo, 'consent' => '1'])->assertRedirect();
        $this->post("/s/{$station->slug}", ['photo' => $this->photo(), 'consent' => '1'])->assertRedirect();

        $this->assertSame(1, Submission::count());
    }

    public function test_uploads_are_rate_limited_per_ip(): void
    {
        config(['fotostation.upload.per_ip_per_hour' => 2]);
        $station = Station::factory()->create();

        foreach ([1, 2] as $seed) {
            $this->post("/s/{$station->slug}", ['photo' => $this->photo(seed: $seed + 10), 'consent' => '1'])->assertRedirect();
        }

        $this->post("/s/{$station->slug}", ['photo' => $this->photo(seed: 99), 'consent' => '1'])
            ->assertStatus(429);
    }

    public function test_thanks_page_requires_signature(): void
    {
        $station = Station::factory()->create();

        $this->get("/s/{$station->slug}/danke")->assertForbidden();
    }
}
