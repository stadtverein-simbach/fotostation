<?php

namespace App\Support;

use RuntimeException;

/**
 * Entfernt Metadaten verlustfrei aus JPEG, PNG und WebP: Die Bilddaten
 * bleiben Byte für Byte erhalten, es wird nichts neu kodiert. Entfernt werden
 * EXIF (inkl. GPS und Seriennummern), XMP, IPTC, Kommentare, eingebettete
 * Zusatzbilder (MPF, z. B. Tiefenkarten) und alles hinter dem Bildende
 * (z. B. Videos von „Motion Photos“).
 *
 * Erhalten bleiben, was für die richtige Darstellung nötig ist: JFIF,
 * ICC-Farbprofil, Adobe-Farbangabe und bei JPEG die Ausrichtung (als
 * neuer, minimaler EXIF-Block nur mit Orientation).
 */
class MetadataStripper
{
    /**
     * @throws RuntimeException bei Dateien, deren Aufbau nicht erkannt wird.
     */
    public function strip(string $bytes, int $imageType, int $orientation = 1): string
    {
        return match ($imageType) {
            IMAGETYPE_JPEG => $this->jpeg($bytes, $orientation),
            IMAGETYPE_PNG => $this->png($bytes),
            IMAGETYPE_WEBP => $this->webp($bytes),
            default => throw new RuntimeException('Format wird nicht unterstützt.'),
        };
    }

    private function jpeg(string $bytes, int $orientation): string
    {
        $length = strlen($bytes);

        if (! str_starts_with($bytes, "\xFF\xD8")) {
            throw new RuntimeException('Kein JPEG.');
        }

        $out = "\xFF\xD8";

        if ($orientation >= 2 && $orientation <= 8) {
            $out .= $this->orientationSegment($orientation);
        }

        $pos = 2;

        while (true) {
            if ($pos >= $length || $bytes[$pos] !== "\xFF") {
                throw new RuntimeException('JPEG-Aufbau nicht erkannt.');
            }

            // Füllbytes überspringen.
            while ($pos < $length && $bytes[$pos] === "\xFF") {
                $pos++;
            }

            if ($pos >= $length) {
                throw new RuntimeException('JPEG endet unerwartet.');
            }

            $marker = ord($bytes[$pos]);
            $pos++;

            if ($marker === 0xD9) {
                // Bildende; alles danach (Motion-Photo-Videos usw.) entfällt.
                return $out."\xFF\xD9";
            }

            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out .= "\xFF".chr($marker);

                continue;
            }

            if ($pos + 2 > $length) {
                throw new RuntimeException('JPEG endet unerwartet.');
            }

            $segmentLength = unpack('n', substr($bytes, $pos, 2))[1];

            if ($segmentLength < 2 || $pos + $segmentLength > $length) {
                throw new RuntimeException('JPEG-Segment beschädigt.');
            }

            $segment = substr($bytes, $pos, $segmentLength);
            $pos += $segmentLength;

            if ($this->keepJpegSegment($marker, substr($segment, 2, 14))) {
                $out .= "\xFF".chr($marker).$segment;
            }

            if ($marker === 0xDA) {
                // Komprimierte Bilddaten bis zum nächsten echten Marker
                // (0xFF00 ist ein maskiertes Byte, 0xFFD0–D7 sind Restart-Marker).
                $start = $pos;
                while (true) {
                    $pos = strpos($bytes, "\xFF", $pos);

                    if ($pos === false || $pos + 1 >= $length) {
                        throw new RuntimeException('JPEG ohne Bildende.');
                    }

                    $next = ord($bytes[$pos + 1]);

                    if ($next === 0x00 || $next === 0xFF || ($next >= 0xD0 && $next <= 0xD7)) {
                        $pos += $next === 0xFF ? 1 : 2;

                        continue;
                    }

                    break;
                }
                $out .= substr($bytes, $start, $pos - $start);
            }
        }
    }

    private function keepJpegSegment(int $marker, string $head): bool
    {
        return match (true) {
            $marker === 0xE0 => str_starts_with($head, "JFIF\0") || str_starts_with($head, "JFXX\0"),
            $marker === 0xE2 => str_starts_with($head, "ICC_PROFILE\0"),
            $marker === 0xEE => str_starts_with($head, 'Adobe'),
            // Übrige APP-Segmente (EXIF, XMP, MPF, IPTC, Herstellerdaten) und Kommentare.
            $marker >= 0xE0 && $marker <= 0xEF, $marker === 0xFE => false,
            default => true,
        };
    }

    /**
     * Minimaler EXIF-Block, der nur die Ausrichtung enthält.
     */
    private function orientationSegment(int $orientation): string
    {
        $tiff = "II*\0".pack('V', 8)
            .pack('v', 1)
            .pack('vvV', 0x0112, 3, 1).pack('vv', $orientation, 0)
            .pack('V', 0);
        $payload = "Exif\0\0".$tiff;

        return "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    }

    private function png(string $bytes): string
    {
        $signature = "\x89PNG\r\n\x1A\n";

        if (! str_starts_with($bytes, $signature)) {
            throw new RuntimeException('Kein PNG.');
        }

        $drop = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];
        $out = $signature;
        $pos = 8;
        $length = strlen($bytes);

        while ($pos + 12 <= $length) {
            $chunkLength = unpack('N', substr($bytes, $pos, 4))[1];
            $type = substr($bytes, $pos + 4, 4);
            $total = 12 + $chunkLength;

            if ($pos + $total > $length) {
                throw new RuntimeException('PNG-Chunk beschädigt.');
            }

            if (! in_array($type, $drop, true)) {
                $out .= substr($bytes, $pos, $total);
            }

            $pos += $total;

            if ($type === 'IEND') {
                return $out;
            }
        }

        throw new RuntimeException('PNG ohne IEND.');
    }

    private function webp(string $bytes): string
    {
        if (strlen($bytes) < 12 || ! str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            throw new RuntimeException('Kein WebP.');
        }

        $riffEnd = min(strlen($bytes), 8 + unpack('V', substr($bytes, 4, 4))[1]);
        $chunks = '';
        $pos = 12;

        while ($pos + 8 <= $riffEnd) {
            $type = substr($bytes, $pos, 4);
            $size = unpack('V', substr($bytes, $pos + 4, 4))[1];
            $total = 8 + $size + ($size % 2);

            if ($pos + 8 + $size > $riffEnd) {
                throw new RuntimeException('WebP-Chunk beschädigt.');
            }

            $chunk = substr($bytes, $pos, $total);

            if ($type === 'VP8X' && $size >= 1) {
                // Flags für EXIF (0x08) und XMP (0x04) löschen.
                $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
            }

            if ($type !== 'EXIF' && $type !== 'XMP ') {
                $chunks .= $chunk;
            }

            $pos += $total;
        }

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }
}
