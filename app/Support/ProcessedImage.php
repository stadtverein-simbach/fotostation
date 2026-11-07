<?php

namespace App\Support;

use DateTimeImmutable;

final readonly class ProcessedImage
{
    public function __construct(
        public string $jpeg,
        public string $thumbWebp,
        public int $width,
        public int $height,
        public string $sha256,
        public string $phash,
        public ?DateTimeImmutable $takenAt,
        public ExifData $exif = new ExifData,
        // Hochgeladene Datei, Bilddaten unverändert, ohne Metadaten.
        public string $source = '',
        public string $sourceExtension = 'jpg',
        public string $sourceMime = 'image/jpeg',
    ) {}
}
