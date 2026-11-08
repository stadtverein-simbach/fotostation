<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Jobs\AlignImage;
use App\Jobs\DetectFaces;
use App\Jobs\MarkForReview;
use App\Jobs\MatchReference;
use App\Models\ConsentVersion;
use App\Models\Station;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ExifJpeg;
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

    public function test_current_consent_mentions_kept_original(): void
    {
        $station = Station::factory()->create();

        $this->assertSame('2026-11-2', ConsentVersion::current()->version);

        $this->get("/s/{$station->slug}")
            ->assertOk()
            ->assertSee('bewahrt das Originalfoto ohne Metadaten nicht öffentlich auf');
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
        $this->assertSame($submission->pathFor('source'), $submission->source_path);
        Storage::disk('photos-private')->assertExists($submission->source_path);
        Storage::disk('photos-private')->assertExists($submission->review_thumb_path);

        Bus::assertChained([MatchReference::class, AlignImage::class, DetectFaces::class, MarkForReview::class]);
    }

    private function photoWithExif(float $latitudeSeconds): UploadedFile
    {
        $image = imagecreatetruecolor(1600, 1200);
        imagefilledrectangle($image, 200, 200, 1000, 800, imagecolorallocate($image, 30, 120, 200));

        $path = ExifJpeg::file(
            $image,
            ifd0: [0x010F => ['ascii', 'Apple'], 0x0110 => ['ascii', 'iPhone 15']],
            exif: [0xA405 => ['short', 26], 0xA431 => ['ascii', 'SERIAL-123']],
            gps: [
                0x0001 => ['ascii', 'N'],
                0x0002 => ['rational', [[48, 1], [16, 1], [(int) round($latitudeSeconds * 100), 100]]],
                0x0003 => ['ascii', 'E'],
                0x0004 => ['rational', [[13, 1], [1, 1], [30, 1]]],
            ],
        );

        return new UploadedFile($path, 'exif.jpg', 'image/jpeg', null, true);
    }

    public function test_selected_exif_data_and_only_the_gps_distance_are_stored(): void
    {
        // Station bei 48° 16' 1.2" N, 13° 1' 30" E; Foto 2" weiter nördlich (ca. 62 m).
        $station = Station::factory()->create(['lat' => 48.267, 'lng' => 13.025]);

        $this->post("/s/{$station->slug}", ['photo' => $this->photoWithExif(3.2), 'consent' => '1'])
            ->assertRedirect();

        $submission = Submission::sole();
        $this->assertSame(['make' => 'Apple', 'model' => 'iPhone 15', 'focal_length_35mm' => 26], $submission->exif);
        $this->assertSame(26, $submission->focal_length_35mm);
        $this->assertSame('main', $submission->lensType());
        $this->assertSame(60, $submission->gps_distance);
        $this->assertTrue($submission->takenNearStation());

        // Auch das gespeicherte Original enthält keine Metadaten.
        $source = Storage::disk('photos-private')->get($submission->source_path);
        $this->assertStringNotContainsString('SERIAL', $source);
        $this->assertStringNotContainsString('iPhone', $source);

        $row = json_encode(DB::table('submissions')->first());
        $this->assertStringNotContainsString('SERIAL', $row);
        $this->assertStringNotContainsString('48.2', $row);
    }

    public function test_gps_distance_is_capped_and_needs_station_coordinates(): void
    {
        $far = Station::factory()->create(['lat' => 48.0, 'lng' => 13.025]);
        $unknown = Station::factory()->create(['lat' => null, 'lng' => null]);

        $this->post("/s/{$far->slug}", ['photo' => $this->photoWithExif(1.2), 'consent' => '1'])->assertRedirect();
        $this->post("/s/{$unknown->slug}", ['photo' => $this->photoWithExif(5.0), 'consent' => '1'])->assertRedirect();

        $farSubmission = Submission::where('station_id', $far->id)->sole();
        $this->assertSame(1000, $farSubmission->gps_distance);
        $this->assertFalse($farSubmission->takenNearStation());

        $this->assertNull(Submission::where('station_id', $unknown->id)->sole()->gps_distance);
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
