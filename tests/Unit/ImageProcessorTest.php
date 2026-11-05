<?php

namespace Tests\Unit;

use App\Support\ImageProcessor;
use App\Support\UnsupportedImageException;
use PHPUnit\Framework\TestCase;

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

    public function test_hash_distance(): void
    {
        $this->assertSame(0, ImageProcessor::hashDistance('00ff00ff00ff00ff', '00ff00ff00ff00ff'));
        $this->assertSame(64, ImageProcessor::hashDistance('0000000000000000', 'ffffffffffffffff'));
        $this->assertSame(1, ImageProcessor::hashDistance('0000000000000000', '0000000000000001'));
    }
}
