<?php

namespace App\Support\Invoicing;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Http;

/**
 * Shared QR PNG rendering for payment QRs (PayBySquare, EPC, Swiss) -
 * chillerlan locally, with the qrserver.com fallback the PayBySquare
 * generator historically used.
 */
final class QrPngRenderer
{
    /** @return string|null data:image/png;base64,... for embedding in PDF */
    public static function dataUri(string $data, int $size = 200): ?string
    {
        if (class_exists(QRCode::class)) {
            $options = new QROptions([
                'outputType' => QRCode::OUTPUT_IMAGE_PNG,
                'scale' => max(4, (int) floor($size / 25)),
                'imageBase64' => true,
            ]);
            $qr = new QRCode($options);

            return $qr->render($data);
        }

        try {
            $response = Http::timeout(5)->connectTimeout(3)->get(
                'https://api.qrserver.com/v1/create-qr-code/',
                ['size' => "{$size}x{$size}", 'data' => $data],
            );
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($response->body());
    }
}
