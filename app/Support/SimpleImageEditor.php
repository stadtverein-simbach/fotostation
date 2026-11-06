<?php

namespace App\Support;

use GdImage;
use RuntimeException;

/**
 * Einfache Bildbearbeitung mit GD für den Betrieb ohne Worker:
 * Zuschnitt auf das Stationsformat und Verpixeln von Bereichen.
 */
class SimpleImageEditor
{
    public function __construct(private int $jpegQuality = 88) {}

    /**
     * Skaliert und schneidet mittig zu, sodass das Bild das Zielformat füllt.
     */
    public function cover(string $contents, int $width, int $height): string
    {
        $image = $this->read($contents);
        $srcW = imagesx($image);
        $srcH = imagesy($image);

        $scale = max($width / $srcW, $height / $srcH);
        $cropW = (int) round($width / $scale);
        $cropH = (int) round($height / $scale);
        $srcX = (int) floor(($srcW - $cropW) / 2);
        $srcY = (int) floor(($srcH - $cropH) / 2);

        $target = imagecreatetruecolor($width, $height);
        imagecopyresampled($target, $image, 0, 0, $srcX, $srcY, $width, $height, $cropW, $cropH);

        return $this->jpeg($target);
    }

    /**
     * Verpixelt die Boxen und liefert öffentliche Fassung (JPEG) und Thumbnail (WebP).
     *
     * @param  array<int, array{x: int|float, y: int|float, w: int|float, h: int|float}>  $boxes  Pixel
     * @return array{jpeg: string, thumb: string}
     */
    public function publish(string $contents, array $boxes, int $thumbWidth): array
    {
        $image = $this->read($contents);

        foreach ($boxes as $box) {
            $this->pixelate($image, (int) $box['x'], (int) $box['y'], (int) $box['w'], (int) $box['h']);
        }

        $width = min($thumbWidth, imagesx($image));
        $height = max(1, (int) round(imagesy($image) * $width / imagesx($image)));
        $thumb = imagecreatetruecolor($width, $height);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        ob_start();
        imagewebp($thumb, null, 80);
        $webp = (string) ob_get_clean();

        return ['jpeg' => $this->jpeg($image), 'thumb' => $webp];
    }

    private function pixelate(GdImage $image, int $x, int $y, int $w, int $h): void
    {
        $x = max(0, $x);
        $y = max(0, $y);
        $w = min($w, imagesx($image) - $x);
        $h = min($h, imagesy($image) - $y);

        if ($w <= 0 || $h <= 0) {
            return;
        }

        // Grobe Blöcke: etwa 12 Blöcke über die kürzere Seite, mindestens 8 px.
        $block = max(8, (int) ceil(min($w, $h) / 12));
        $smallW = max(1, (int) ceil($w / $block));
        $smallH = max(1, (int) ceil($h / $block));

        $small = imagecreatetruecolor($smallW, $smallH);
        imagecopyresampled($small, $image, 0, 0, $x, $y, $smallW, $smallH, $w, $h);
        imagecopyresized($image, $small, $x, $y, 0, 0, $w, $h, $smallW, $smallH);
    }

    private function read(string $contents): GdImage
    {
        $image = @imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('Bild konnte nicht gelesen werden.');
        }

        return $image;
    }

    private function jpeg(GdImage $image): string
    {
        imageinterlace($image, true);
        ob_start();
        imagejpeg($image, null, $this->jpegQuality);

        return (string) ob_get_clean();
    }
}
