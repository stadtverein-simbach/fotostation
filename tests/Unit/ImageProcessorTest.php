<?php

namespace Tests\Unit;

use App\Support\ImageProcessor;
use App\Support\UnsupportedImageException;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExifJpeg;

class ImageProcessorTest extends TestCase
{
    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($i = 0; $i < 50; $i++) {
            $color = imagecolorallocate($image, $i * 5, 255 - $i * 5, ($i * 37) % 255);
            imagefilledrectangle($image, $i * 20, $i * 10, $i * 20 + 300, $i * 10 + 200, $color);
        }
        $path = tempnam(sys_get_temp_dir(), 'img');
        imagejpeg($image, $path, 90);

        return $path;
    }

    public function test_large_photos_are_scaled_down_and_reencoded(): void
    {
        $result = (new ImageProcessor(maxEdge: 3000))->process($this->jpeg(4000, 3000));

        $this->assertSame(3000, $result->width);
        $this->assertSame(2250, $result->height);
        $this->assertStringStartsWith("\xFF\xD8", $result->jpeg);
        $this->assertStringStartsWith('RIFF', $result->thumbWebp);
        $this->assertSame(64, strlen($result->sha256));
        $this->assertSame(16, strlen($result->phash));
    }

    public function test_source_keeps_full_resolution_without_metadata(): void
    {
        $path = ExifJpeg::file(
            imagecreatetruecolor(4000, 3000),
            ifd0: [0x0110 => ['ascii', 'Pixel 8'], 0x0112 => ['short', 6]],
            gps: [0x0001 => ['ascii', 'N'], 0x0002 => ['rational', [[48, 1], [16, 1], [12, 10]]]],
        );

        $result = (new ImageProcessor(maxEdge: 3000))->process($path);

        // Arbeitsfassung gedreht und verkleinert, Original unverändert groß.
        $this->assertSame([2250, 3000], [$result->width, $result->height]);
        $this->assertSame([4000, 3000], array_slice(getimagesizefromstring($result->source), 0, 2));
        $this->assertSame(['jpg', 'image/jpeg'], [$result->sourceExtension, $result->sourceMime]);
        $this->assertStringNotContainsString('Pixel 8', $result->source);

        $original = (string) file_get_contents($path);
        $this->assertSame(substr($original, strpos($original, "\xFF\xDB")), substr($result->source, strpos($result->source, "\xFF\xDB")));
    }

    public function test_png_source_stays_png(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        imagepng(imagecreatetruecolor(1200, 1000), $path);

        $result = (new ImageProcessor)->process($path);

        $this->assertSame(file_get_contents($path), $result->source);
        $this->assertSame(['png', 'image/png'], [$result->sourceExtension, $result->sourceMime]);
    }

    public function test_too_small_photos_are_rejected(): void
    {
        $this->expectException(UnsupportedImageException::class);

        (new ImageProcessor(minEdge: 1000))->process($this->jpeg(800, 600));
    }

    public function test_non_images_are_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($path, 'kein Bild');

        $this->expectException(UnsupportedImageException::class);

        (new ImageProcessor)->process($path);
    }

    private function canvas(): \GdImage
    {
        $image = imagecreatetruecolor(1600, 1200);
        imagefilledrectangle($image, 100, 100, 900, 700, imagecolorallocate($image, 200, 40, 40));

        return $image;
    }

    public function test_selected_exif_fields_are_kept_and_the_file_is_stripped(): void
    {
        $path = ExifJpeg::file(
            $this->canvas(),
            ifd0: [
                0x010F => ['ascii', 'Google'],
                0x0110 => ['ascii', 'Pixel 8'],
                0x0131 => ['ascii', 'HDR+ 1.0'],
                0x0132 => ['ascii', '2026:06:01 14:00:00'],
            ],
            exif: [
                0x829A => ['rational', [[1, 250]]],
                0x829D => ['rational', [[17, 10]]],
                0x8827 => ['short', 100],
                0x9003 => ['ascii', '2026:06:01 14:00:00'],
                0x9011 => ['ascii', '+00:00'],
                0x9209 => ['short', 0],
                0x920A => ['rational', [[690, 100]]],
                0xA405 => ['short', 13],
                0xA431 => ['ascii', 'SERIAL-123'],
            ],
            gps: [
                0x0001 => ['ascii', 'N'],
                0x0002 => ['rational', [[48, 1], [16, 1], [12, 10]]],
                0x0003 => ['ascii', 'E'],
                0x0004 => ['rational', [[13, 1], [1, 1], [30, 1]]],
            ],
        );

        $result = (new ImageProcessor(timezone: 'Europe/Berlin'))->process($path);

        $this->assertSame('Pixel 8', $result->exif->model);
        $this->assertSame(13, $result->exif->focalLength35mm);
        $this->assertSame(100, $result->exif->iso);
        $this->assertFalse($result->exif->flash);
        $this->assertTrue($result->exif->hasLocation());
        $this->assertStringNotContainsString('SERIAL', json_encode($result->exif->toArray()));

        // 14:00 UTC laut OffsetTimeOriginal ist 16:00 Sommerzeit in Berlin.
        $this->assertSame('2026-06-01 16:00:00 +02:00', $result->takenAt->format('Y-m-d H:i:s P'));

        // Die gespeicherte Fassung enthält keinerlei EXIF mehr.
        $this->assertStringNotContainsString('Pixel 8', $result->jpeg);
        $this->assertStringNotContainsString('Exif', $result->jpeg);
    }

    public function test_taken_at_without_offset_uses_local_time(): void
    {
        $path = ExifJpeg::file($this->canvas(), exif: [0x9003 => ['ascii', '2026:01:15 09:30:00']]);

        $result = (new ImageProcessor(timezone: 'Europe/Berlin'))->process($path);

        $this->assertSame('2026-01-15 09:30:00 +01:00', $result->takenAt->format('Y-m-d H:i:s P'));
        $this->assertSame([], $result->exif->toArray());
    }

    public function test_hash_distance(): void
    {
        $this->assertSame(0, ImageProcessor::hashDistance('00ff00ff00ff00ff', '00ff00ff00ff00ff'));
        $this->assertSame(64, ImageProcessor::hashDistance('0000000000000000', 'ffffffffffffffff'));
        $this->assertSame(1, ImageProcessor::hashDistance('0000000000000000', '0000000000000001'));
    }
}
