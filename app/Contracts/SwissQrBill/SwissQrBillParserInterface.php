<?php

declare(strict_types=1);

namespace App\Contracts\SwissQrBill;

use App\Services\SwissQrBill\ParsedQrBill;

interface SwissQrBillParserInterface
{
    /**
     * Decode a raw SPC payload (the text encoded in the QR code).
     */
    public function parsePayload(string $payload): ParsedQrBill;

    /**
     * Find and decode the QR code in an uploaded PDF or raster image (PNG, JPG, WebP).
     */
    public function parseFile(string $bytes): ParsedQrBill;
}
