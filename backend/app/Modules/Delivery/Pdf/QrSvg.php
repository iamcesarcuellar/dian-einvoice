<?php

namespace App\Modules\Delivery\Pdf;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * A QR code as a plain SVG: a white square with its quiet zone and one black rectangle per run of
 * dark modules in each row. No paths, transforms or fill rules, so the SVG support of dompdf draws
 * it exactly. The matrix comes from bacon/bacon-qr-code (BSD-2-Clause), error correction level M.
 */
final class QrSvg
{
    /** Modules of white around the code, as the QR specification asks. */
    private const int QUIET_ZONE = 4;

    public static function render(string $text): string
    {
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ENCODING, null, false)->getMatrix();
        $size = $matrix->getWidth();
        $total = $size + 2 * self::QUIET_ZONE;
        $rects = '';

        for ($y = 0; $y < $size; $y++) {
            $start = null;

            for ($x = 0; $x <= $size; $x++) {
                $dark = $x < $size && $matrix->get($x, $y) === 1;

                if ($dark && $start === null) {
                    $start = $x;
                } elseif (! $dark && $start !== null) {
                    $rects .= sprintf('<rect x="%d" y="%d" width="%d" height="1" fill="#000000"/>', $start + self::QUIET_ZONE, $y + self::QUIET_ZONE, $x - $start);
                    $start = null;
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d"><rect x="0" y="0" width="%1$d" height="%1$d" fill="#ffffff"/>%2$s</svg>',
            $total,
            $rects,
        );
    }
}
