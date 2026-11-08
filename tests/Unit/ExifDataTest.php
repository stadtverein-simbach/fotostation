<?php

namespace Tests\Unit;

use App\Support\ExifData;
use PHPUnit\Framework\TestCase;

class ExifDataTest extends TestCase
{
    public function test_selected_fields_are_parsed(): void
    {
        $exif = ExifData::fromArray([
            'Make' => "Apple\0\0",
            'Model' => 'iPhone 15 Pro',
            'Software' => '17.1',
            'FocalLength' => '6765/1000',
            'FocalLengthIn35mmFilm' => 24,
            'DigitalZoomRatio' => '0/1',
            'ExposureTime' => '1/120',
            'FNumber' => '178/100',
            'ISOSpeedRatings' => 64,
            'Flash' => 16,
            'DateTimeOriginal' => '2026:06:01 12:00:00',
            'DateTime' => '2026:06:01 12:00:00',
            'BodySerialNumber' => 'ABC123',
            'Artist' => 'Max Mustermann',
        ]);

        $this->assertSame([
            'make' => 'Apple',
            'model' => 'iPhone 15 Pro',
            'software' => '17.1',
            'focal_length' => 6.765,
            'focal_length_35mm' => 24,
            'exposure_time' => round(1 / 120, 6),
            'f_number' => 1.78,
            'iso' => 64,
            'flash' => false,
            'modified_after_capture' => false,
        ], $exif->toArray());
    }

    public function test_edits_after_capture_are_detected(): void
    {
        $exif = ExifData::fromArray([
            'DateTimeOriginal' => '2026:06:01 12:00:00',
            'DateTime' => '2026:06:02 09:30:00',
            'Software' => 'Adobe Photoshop 26.0',
            'Flash' => 25,
            'DigitalZoomRatio' => '200/100',
        ]);

        $this->assertTrue($exif->modifiedAfterCapture);
        $this->assertTrue($exif->flash);
        $this->assertSame(2.0, $exif->digitalZoom);
    }

    public function test_gps_is_used_for_distance_but_never_exported(): void
    {
        // 48° 16' 1.2" N, 13° 1' 30" E
        $exif = ExifData::fromArray([
            'GPSLatitude' => ['48/1', '16/1', '12/10'],
            'GPSLatitudeRef' => 'N',
            'GPSLongitude' => ['13/1', '1/1', '30/1'],
            'GPSLongitudeRef' => 'E',
        ]);

        $this->assertTrue($exif->hasLocation());
        $this->assertEqualsWithDelta(0.0, $exif->distanceTo(48.267, 13.025), 1.0);
        // 0,001° Breite sind etwa 111 m.
        $this->assertEqualsWithDelta(111.2, $exif->distanceTo(48.268, 13.025), 1.0);
        $this->assertSame([], $exif->toArray());
    }

    public function test_southern_and_western_coordinates_are_negative(): void
    {
        $exif = ExifData::fromArray([
            'GPSLatitude' => ['33/1', '52/1', '0/1'],
            'GPSLatitudeRef' => 'S',
            'GPSLongitude' => ['151/1', '12/1', '0/1'],
            'GPSLongitudeRef' => 'W',
        ]);

        $this->assertEqualsWithDelta(0.0, $exif->distanceTo(-33.866667, -151.2), 1.0);
    }

    public function test_missing_or_broken_values_are_ignored(): void
    {
        $exif = ExifData::fromArray([
            'FocalLength' => '5/0',
            'ExposureTime' => 'abc',
            'FocalLengthIn35mmFilm' => 0,
            'Model' => "   \x01 ",
            'GPSLatitude' => ['0/1', '0/1', '0/1'],
            'GPSLatitudeRef' => 'N',
            'GPSLongitude' => ['0/1', '0/1', '0/1'],
            'GPSLongitudeRef' => 'E',
        ]);

        $this->assertSame([], $exif->toArray());
        $this->assertFalse($exif->hasLocation());
        $this->assertNull($exif->distanceTo(48.0, 13.0));
        $this->assertSame([], ExifData::fromArray([])->toArray());
    }
}
