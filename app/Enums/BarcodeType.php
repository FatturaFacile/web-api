<?php

declare(strict_types=1);

namespace App\Enums;

use App\Exceptions\BarcodeGenerationException;

enum BarcodeType: string
{
    case Ean13 = 'ean13';
    case Ean8 = 'ean8';
    case Code128 = 'code128';
    case QrCode = 'qrcode';

    /**
     * Resolve a type from user input.
     *
     * @throws BarcodeGenerationException when the type is not supported
     */
    public static function fromInput(string $type): self
    {
        return $type
            |> trim(...)
            |> strtolower(...)
            |> self::tryFrom(...) ?? throw BarcodeGenerationException::because(BarcodeGenerationException::UNSUPPORTED_TYPE, $type);
    }

    /**
     * Two-dimensional symbologies are rendered as a square matrix instead of bars.
     */
    public function isMatrix(): bool
    {
        return $this === self::QrCode;
    }
}
