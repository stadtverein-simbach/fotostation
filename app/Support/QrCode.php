<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrCode
{
    /**
     * SVG für den Druck auf dem Schild. Fehlerkorrektur „Q“ (25 %), damit der
     * Code auch bei Verschmutzung oder Kratzern im Außenbereich lesbar bleibt.
     */
    public static function svg(string $url, int $size = 1000): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 4), new SvgImageBackEnd));

        return $writer->writeString($url, Encoder::DEFAULT_BYTE_MODE_ENCODING, ErrorCorrectionLevel::Q());
    }
}
