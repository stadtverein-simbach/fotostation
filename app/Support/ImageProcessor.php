<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use GdImage;
use RuntimeException;

/**
 * Verarbeitet ein hochgeladenes Foto synchron im Request, bevor irgendetwas
 * gespeichert wird:
 *
 * - liest das Aufnahmedatum (EXIF DateTimeOriginal, mit Zeitzone aus
 *   OffsetTimeOriginal, falls vorhanden) und einige Kameraangaben (ExifData),
 * - dreht das Bild gemäß EXIF-Orientation,
 * - verkleinert auf eine maximale Kantenlänge,
 * - kodiert neu als JPEG (dabei gehen alle Metadaten inkl. GPS verloren),
 * - erzeugt eine kleine WebP-Vorschau für die Moderation,
 * - berechnet SHA-256 und einen Perceptual Hash (dHash) gegen Duplikate.
 *
 * Nutzt nur GD und exif, beides in Laravel Cloud verfügbar.
 */
class ImageProcessor
{
    public function __construct(
        private int $maxEdge = 3000,
        private int $minEdge = 1000,
        private int $jpegQuality = 88,
        private int $thumbWidth = 480,
        private string $timezone = 'Europe/Berlin',
    ) {}

    public function process(string $path): ProcessedImage
    {
        $info = @getimagesize($path);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new UnsupportedImageException('Dateiformat wird nicht unterstützt.');
        }

        $exif = $info[2] === IMAGETYPE_JPEG ? (@exif_read_data($path) ?: []) : [];

        $image = @imagecreatefromstring((string) file_get_contents($path));

        if (! $image instanceof GdImage) {
            throw new UnsupportedImageException('Bild konnte nicht gelesen werden.');
        }

        $image = $this->applyOrientation($image, (int) ($exif['Orientation'] ?? 1));
        $image = $this->limitSize($image);

        $width = imagesx($image);
        $height = imagesy($image);

        if (min($width, $height) < $this->minEdge) {
            throw new UnsupportedImageException('Das Foto ist zu klein.');
        }

        $jpeg = $this->encodeJpeg($image);

        return new ProcessedImage(
            jpeg: $jpeg,
            thumbWebp: $this->thumbnail($image),
            width: $width,
            height: $height,
            sha256: hash('sha256', $jpeg),
            phash: $this->dHash($image),
            takenAt: $this->takenAt($exif),
            exif: ExifData::fromArray($exif),
        );
    }

    /**
     * Hamming-Abstand zweier dHash-Werte (0 = identisch, 64 = maximal verschieden).
     */
    public static function hashDistance(string $a, string $b): int
    {
        $bytesA = hex2bin(str_pad($a, 16, '0', STR_PAD_LEFT));
        $bytesB = hex2bin(str_pad($b, 16, '0', STR_PAD_LEFT));
        $distance = 0;

        for ($i = 0; $i < 8; $i++) {
            $distance += substr_count(decbin(ord($bytesA[$i]) ^ ord($bytesB[$i])), '1');
        }

        return $distance;
    }

    private function applyOrientation(GdImage $image, int $orientation): GdImage
    {
        $result = match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, 0),
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => imagerotate($this->flip($image, IMG_FLIP_HORIZONTAL), 90, 0),
            6 => imagerotate($image, -90, 0),
            7 => imagerotate($this->flip($image, IMG_FLIP_HORIZONTAL), -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if (! $result instanceof GdImage) {
            throw new RuntimeException('Bild konnte nicht gedreht werden.');
        }

        return $result;
    }

    private function flip(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    private function limitSize(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= $this->maxEdge) {
            return $image;
        }

        $factor = $this->maxEdge / $longest;

        return $this->resize($image, (int) round($width * $factor), (int) round($height * $factor));
    }

    private function resize(GdImage $image, int $width, int $height): GdImage
    {
        $target = imagecreatetruecolor($width, $height);
        imagecopyresampled($target, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $target;
    }

    private function encodeJpeg(GdImage $image): string
    {
        // PNG/WebP mit Transparenz auf weißen Hintergrund legen.
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imageinterlace($canvas, true);

        ob_start();
        imagejpeg($canvas, null, $this->jpegQuality);

        return (string) ob_get_clean();
    }

    private function thumbnail(GdImage $image): string
    {
        $width = min($this->thumbWidth, imagesx($image));
        $height = (int) round(imagesy($image) * $width / imagesx($image));
        $thumb = $this->resize($image, $width, $height);

        ob_start();
        imagewebp($thumb, null, 80);

        return (string) ob_get_clean();
    }

    /**
     * Difference Hash: 9x8 Graustufen, je Zeile 8 Vergleiche benachbarter Pixel.
     */
    private function dHash(GdImage $image): string
    {
        $small = $this->resize($image, 9, 8);
        $bits = '';

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= $this->gray($small, $x, $y) > $this->gray($small, $x + 1, $y) ? '1' : '0';
            }
        }

        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }

        return $hex;
    }

    private function gray(GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);

        return 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
    }

    private function takenAt(array $exif): ?DateTimeImmutable
    {
        if (is_string($exif['DateTimeOriginal'] ?? null)) {
            $value = $exif['DateTimeOriginal'];
            // Ältere PHP-Versionen kennen die Namen der Offset-Tags noch nicht.
            $offset = $exif['OffsetTimeOriginal'] ?? $exif['UndefinedTag:0x9011'] ?? null;
        } else {
            $value = $exif['DateTimeDigitized'] ?? null;
            $offset = $exif['OffsetTimeDigitized'] ?? $exif['UndefinedTag:0x9012'] ?? null;
        }

        if (! is_string($value)) {
            return null;
        }

        $local = new DateTimeZone($this->timezone);
        // Ohne Zeitzonenangabe im Foto nehmen wir die der Anwendung an.
        $zone = is_string($offset) && preg_match('/^[+-](0\d|1[0-4]):[0-5]\d$/', trim($offset))
            ? new DateTimeZone(trim($offset))
            : $local;

        $date = DateTimeImmutable::createFromFormat('!Y:m:d H:i:s', trim($value), $zone);

        if ($date === false) {
            return null;
        }

        $date = $date->setTimezone($local);

        // Offensichtlich falsche Kameradaten (Werkseinstellung, Zukunft) verwerfen.
        $now = new DateTimeImmutable('now', new DateTimeZone($this->timezone));
        if ($date->format('Y') < 2000 || $date > $now->modify('+1 day')) {
            return null;
        }

        return $date;
    }
}
