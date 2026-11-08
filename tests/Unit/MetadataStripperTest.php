<?php

namespace Tests\Unit;

use App\Support\MetadataStripper;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ExifJpeg;

class MetadataStripperTest extends TestCase
{
    private function image(): \GdImage
    {
        $image = imagecreatetruecolor(320, 240);
        imagefilledrectangle($image, 20, 20, 200, 150, imagecolorallocate($image, 200, 40, 40));

        return $image;
    }

    private function jpegWithMetadata(): string
    {
        $path = ExifJpeg::file(
            $this->image(),
            ifd0: [0x010F => ['ascii', 'Apple'], 0x0110 => ['ascii', 'iPhone 15'], 0x0112 => ['short', 6]],
            exif: [0xA431 => ['ascii', 'SERIAL-123']],
            gps: [0x0001 => ['ascii', 'N'], 0x0002 => ['rational', [[48, 1], [16, 1], [12, 10]]]],
        );
        $jpeg = (string) file_get_contents($path);

        // XMP-Block nach SOI und ein angehängtes „Motion-Photo-Video“ hinter EOI.
        $xmp = "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>GPS 48.2</x:xmpmeta>";
        $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($xmp) + 2).$xmp.substr($jpeg, 2);

        return $jpeg.'ftypmp42 VIDEO-DATA';
    }

    private function scanData(string $jpeg): string
    {
        // Ab der ersten Quantisierungstabelle: Tabellen, Frame, Bilddaten.
        return substr($jpeg, (int) strpos($jpeg, "\xFF\xDB"));
    }

    public function test_jpeg_metadata_is_removed_and_image_data_is_unchanged(): void
    {
        $original = $this->jpegWithMetadata();

        $stripped = (new MetadataStripper)->strip($original, IMAGETYPE_JPEG);

        foreach (['Exif', 'Apple', 'iPhone', 'SERIAL', 'xmpmeta', 'CREATOR', 'VIDEO-DATA'] as $needle) {
            $this->assertStringNotContainsString($needle, $stripped, $needle);
        }

        $this->assertStringStartsWith("\xFF\xD8", $stripped);
        $this->assertStringEndsWith("\xFF\xD9", $stripped);
        $this->assertStringContainsString("JFIF\0", $stripped);
        $this->assertSame(
            $this->scanData(substr($original, 0, (int) strrpos($original, "\xFF\xD9") + 2)),
            $this->scanData($stripped),
        );
        $this->assertInstanceOf(\GdImage::class, imagecreatefromstring($stripped));
    }

    public function test_jpeg_orientation_is_kept_as_minimal_exif(): void
    {
        $stripped = (new MetadataStripper)->strip($this->jpegWithMetadata(), IMAGETYPE_JPEG, 6);

        $path = tempnam(sys_get_temp_dir(), 'strip');
        file_put_contents($path, $stripped);
        $exif = exif_read_data($path);

        $this->assertSame(6, $exif['Orientation']);
        $this->assertArrayNotHasKey('Make', $exif);
        $this->assertArrayNotHasKey('GPS_IFD_Pointer', $exif);
        $this->assertStringNotContainsString('SERIAL', $stripped);
    }

    public function test_progressive_jpeg_is_kept_intact(): void
    {
        $image = $this->image();
        imageinterlace($image, true);
        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = (string) ob_get_clean();

        $stripped = (new MetadataStripper)->strip($jpeg, IMAGETYPE_JPEG);

        $this->assertSame($this->scanData($jpeg), $this->scanData($stripped));
    }

    public function test_png_text_chunks_are_removed(): void
    {
        ob_start();
        imagepng($this->image());
        $png = (string) ob_get_clean();

        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        // Nach IHDR (8 Byte Signatur + 25 Byte Chunk) Metadaten einfügen.
        $withMeta = substr($png, 0, 33).$chunk('tEXt', "Author\0Max Mustermann").$chunk('eXIf', 'MM-GPS').substr($png, 33).'TRAILER';

        $stripped = (new MetadataStripper)->strip($withMeta, IMAGETYPE_PNG);

        $this->assertSame($png, $stripped);
    }

    public function test_webp_exif_and_xmp_are_removed(): void
    {
        ob_start();
        imagewebp($this->image(), null, 80);
        $simple = (string) ob_get_clean();
        $vp8 = substr($simple, 12);

        $chunk = fn (string $type, string $data): string => $type.pack('V', strlen($data)).$data.(strlen($data) % 2 ? "\0" : '');
        $canvas = substr(pack('V', 319), 0, 3).substr(pack('V', 239), 0, 3);
        $chunks = $chunk('VP8X', chr(0x0C)."\0\0\0".$canvas).$vp8.$chunk('EXIF', 'GPS-SERIAL').$chunk('XMP ', '<x:xmpmeta/>');
        $webp = 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;

        $stripped = (new MetadataStripper)->strip($webp, IMAGETYPE_WEBP);

        $this->assertStringNotContainsString('GPS-SERIAL', $stripped);
        $this->assertStringNotContainsString('xmpmeta', $stripped);
        $this->assertStringContainsString($vp8, $stripped);
        $this->assertSame(0, ord($stripped[20]) & 0x0C);
        $this->assertSame(strlen($stripped) - 8, unpack('V', substr($stripped, 4, 4))[1]);
        $this->assertNotFalse(getimagesizefromstring($stripped));
    }

    public function test_broken_files_are_refused(): void
    {
        $this->expectException(RuntimeException::class);

        (new MetadataStripper)->strip("\xFF\xD8\xFF\xE1\x00", IMAGETYPE_JPEG);
    }
}
