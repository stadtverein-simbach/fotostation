<?php

namespace App\Support;

/**
 * Die wenigen EXIF-Angaben, die wir aus einem Upload übernehmen. Alles andere
 * (Seriennummern, Namen, MakerNotes, Vorschaubild) wird nie gelesen.
 *
 * Die GPS-Position bleibt nur im Speicher des Requests, um den Abstand zur
 * Station zu berechnen. Sie wird nicht gespeichert und ist bewusst nicht Teil
 * von toArray().
 */
final readonly class ExifData
{
    public function __construct(
        public ?string $make = null,
        public ?string $model = null,
        public ?string $software = null,
        public ?float $focalLength = null,
        public ?int $focalLength35mm = null,
        public ?float $digitalZoom = null,
        public ?float $exposureTime = null,
        public ?float $fNumber = null,
        public ?int $iso = null,
        public ?bool $flash = null,
        public ?bool $modifiedAfterCapture = null,
        private ?float $latitude = null,
        private ?float $longitude = null,
    ) {}

    public static function fromArray(array $exif): self
    {
        $original = self::date($exif['DateTimeOriginal'] ?? null);
        $modified = self::date($exif['DateTime'] ?? null);

        $zoom = self::rational($exif['DigitalZoomRatio'] ?? null);
        $iso = $exif['ISOSpeedRatings'] ?? $exif['PhotographicSensitivity'] ?? null;
        if (is_array($iso)) {
            $iso = reset($iso);
        }

        return new self(
            make: self::text($exif['Make'] ?? null),
            model: self::text($exif['Model'] ?? null),
            software: self::text($exif['Software'] ?? null),
            focalLength: self::positive(self::rational($exif['FocalLength'] ?? null)),
            focalLength35mm: self::positiveInt($exif['FocalLengthIn35mmFilm'] ?? null),
            // 0 heißt laut Standard „kein Digitalzoom“.
            digitalZoom: $zoom !== null && $zoom > 0 ? round($zoom, 2) : null,
            exposureTime: self::positive(self::rational($exif['ExposureTime'] ?? null)),
            fNumber: self::positive(self::rational($exif['FNumber'] ?? null)),
            iso: self::positiveInt($iso),
            flash: isset($exif['Flash']) && is_numeric($exif['Flash']) ? ((int) $exif['Flash'] & 1) === 1 : null,
            // Änderungsdatum deutlich nach dem Aufnahmedatum: vermutlich bearbeitet.
            modifiedAfterCapture: $original !== null && $modified !== null ? $modified - $original > 60 : null,
            latitude: self::coordinate($exif['GPSLatitude'] ?? null, $exif['GPSLatitudeRef'] ?? null, 90),
            longitude: self::coordinate($exif['GPSLongitude'] ?? null, $exif['GPSLongitudeRef'] ?? null, 180),
        );
    }

    public function hasLocation(): bool
    {
        // 0/0 ist bei vielen Geräten ein Platzhalter ohne Empfang.
        return $this->latitude !== null && $this->longitude !== null
            && ! ($this->latitude == 0.0 && $this->longitude == 0.0);
    }

    /**
     * Abstand zur angegebenen Position in Metern (Haversine), null ohne GPS.
     */
    public function distanceTo(float $latitude, float $longitude): ?float
    {
        if (! $this->hasLocation()) {
            return null;
        }

        $earthRadius = 6371000.0;
        $dLat = deg2rad($latitude - $this->latitude);
        $dLng = deg2rad($longitude - $this->longitude);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->latitude)) * cos(deg2rad($latitude)) * sin($dLng / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
    }

    /**
     * Für die Datenbank (ohne GPS). Leere Werte entfallen.
     *
     * @return array<string, scalar>
     */
    public function toArray(): array
    {
        return array_filter([
            'make' => $this->make,
            'model' => $this->model,
            'software' => $this->software,
            'focal_length' => $this->focalLength,
            'focal_length_35mm' => $this->focalLength35mm,
            'digital_zoom' => $this->digitalZoom,
            'exposure_time' => $this->exposureTime,
            'f_number' => $this->fNumber,
            'iso' => $this->iso,
            'flash' => $this->flash,
            'modified_after_capture' => $this->modifiedAfterCapture,
        ], fn ($value) => $value !== null);
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // Steuerzeichen und Füllbytes entfernen, Länge begrenzen.
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
        $value = mb_substr(mb_convert_encoding($value, 'UTF-8', 'UTF-8'), 0, 64);

        return $value === '' ? null : $value;
    }

    /**
     * EXIF-Brüche wie "1/120" oder "45/10".
     */
    private static function rational(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        if (preg_match('#^\s*(\d+(?:\.\d+)?)\s*/\s*(\d+(?:\.\d+)?)\s*$#', $value, $m)) {
            return (float) $m[2] > 0 ? (float) $m[1] / (float) $m[2] : null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private static function positive(?float $value): ?float
    {
        return $value !== null && $value > 0 && is_finite($value) ? round($value, 6) : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function date(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y:m:d H:i:s', trim($value), new \DateTimeZone('UTC'));

        return $date === false ? null : $date->getTimestamp();
    }

    /**
     * Grad, Minuten, Sekunden als Brüche plus Himmelsrichtung.
     */
    private static function coordinate(mixed $parts, mixed $ref, int $max): ?float
    {
        if (! is_array($parts) || count($parts) !== 3) {
            return null;
        }

        [$degrees, $minutes, $seconds] = array_map(self::rational(...), array_values($parts));

        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $value = $degrees + $minutes / 60 + $seconds / 3600;

        if (in_array(strtoupper(trim((string) $ref)), ['S', 'W'], true)) {
            $value = -$value;
        }

        return abs($value) <= $max ? $value : null;
    }
}
