<?php

namespace Tests\Support;

/**
 * Schreibt einen EXIF-Block (APP1) in ein JPEG. GD kann das nicht selbst.
 *
 * Werte: ['ascii', 'Text'], ['short', 3], ['long', 5], ['rational', [[1, 120], ...]].
 */
final class ExifJpeg
{
    private const TYPES = ['ascii' => 2, 'short' => 3, 'long' => 4, 'rational' => 5];

    /**
     * @param  array<int, array{0: string, 1: mixed}>  $ifd0
     * @param  array<int, array{0: string, 1: mixed}>  $exif
     * @param  array<int, array{0: string, 1: mixed}>  $gps
     */
    public static function inject(string $jpeg, array $ifd0 = [], array $exif = [], array $gps = []): string
    {
        $tiff = self::tiff($ifd0, $exif, $gps);
        $payload = "Exif\0\0".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        // Direkt nach SOI einfügen.
        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    /**
     * Schreibt das JPEG mit EXIF in eine temporäre Datei und gibt den Pfad zurück.
     */
    public static function file(\GdImage $image, array $ifd0 = [], array $exif = [], array $gps = []): string
    {
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'exif');
        file_put_contents($path, self::inject($jpeg, $ifd0, $exif, $gps));

        return $path;
    }

    private static function tiff(array $ifd0, array $exif, array $gps): string
    {
        $pointers = fn (int $exifOffset, int $gpsOffset): array => $ifd0
            + ($exif !== [] ? [0x8769 => ['long', $exifOffset]] : [])
            + ($gps !== [] ? [0x8825 => ['long', $gpsOffset]] : []);

        // Erst mit Platzhaltern die Größe von IFD0 bestimmen, dann die Zeiger setzen.
        $exifOffset = 8 + strlen(self::ifd($pointers(0, 0), 8));
        $exifBytes = $exif !== [] ? self::ifd($exif, $exifOffset) : '';
        $gpsOffset = $exifOffset + strlen($exifBytes);
        $gpsBytes = $gps !== [] ? self::ifd($gps, $gpsOffset) : '';

        return "II*\0".pack('V', 8).self::ifd($pointers($exifOffset, $gpsOffset), 8).$exifBytes.$gpsBytes;
    }

    /**
     * Ein IFD mit anschließendem Datenbereich, beginnend bei $offset.
     */
    private static function ifd(array $entries, int $offset): string
    {
        ksort($entries);
        $dataOffset = $offset + 2 + 12 * count($entries) + 4;
        $head = pack('v', count($entries));
        $data = '';

        foreach ($entries as $tag => [$type, $value]) {
            [$count, $bytes] = match ($type) {
                'ascii' => [strlen($value) + 1, $value."\0"],
                'short' => [1, pack('v', $value)],
                'long' => [1, pack('V', $value)],
                'rational' => [count($value), implode('', array_map(fn (array $r) => pack('VV', ...$r), $value))],
            };

            $head .= pack('vvV', $tag, self::TYPES[$type], $count);

            if (strlen($bytes) <= 4) {
                $head .= str_pad($bytes, 4, "\0");
            } else {
                $head .= pack('V', $dataOffset + strlen($data));
                $data .= $bytes.(strlen($bytes) % 2 ? "\0" : '');
            }
        }

        return $head.pack('V', 0).$data;
    }
}
