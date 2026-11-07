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
    ) {}
}
