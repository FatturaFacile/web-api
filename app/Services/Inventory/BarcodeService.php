<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\BarcodeType;
use App\Exceptions\BarcodeGenerationException;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Picqer\Barcode\Exceptions\BarcodeException;
use Picqer\Barcode\Exceptions\InvalidCharacterException;
use Picqer\Barcode\Exceptions\InvalidCheckDigitException;
use Picqer\Barcode\Exceptions\InvalidLengthException;
use Throwable;

/**
 * Generates 1D (EAN-13, EAN-8, Code 128; picqer/php-barcode-generator) and 2D (QR Code; endroid/qr-code) barcodes
 * as SVG or PNG. Values are validated first so callers always get a typed {@see BarcodeGenerationException}.
 */
class BarcodeService
{
    public const FORMAT_SVG = 'svg';

    public const FORMAT_PNG = 'png';

    private const CODE128_MAX_LENGTH = 80;

    private const QR_MAX_LENGTH = 1000;

    /**
     * Pixel edge length of generated QR code images.
     */
    private const QR_PIXEL_SIZE = 300;

    /**
     * Validate a value for a barcode type and return the final value to encode. EAN values get their check digit:
     * 12 digits become a valid EAN-13 and 7 digits a valid EAN-8; complete values must carry a correct check digit.
     *
     * @throws BarcodeGenerationException
     */
    public function normalize(BarcodeType $type, string $value): string
    {
        if ($value === '') {
            throw BarcodeGenerationException::because(BarcodeGenerationException::EMPTY_VALUE, $value);
        }

        return match ($type) {
            BarcodeType::Ean13 => $this->normalizeEan($value, 12),
            BarcodeType::Ean8 => $this->normalizeEan($value, 7),
            BarcodeType::Code128 => $this->normalizeCode128($value),
            BarcodeType::QrCode => $this->normalizeQr($value),
        };
    }

    /**
     * Check digit of an EAN payload (the rightmost payload digit has weight 3, then 1, 3, 1, ...).
     */
    public function eanCheckDigit(string $payload): int
    {
        $sum = 0;

        foreach (array_reverse(str_split($payload)) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * @throws BarcodeGenerationException
     */
    public function svg(BarcodeType $type, string $value, int $scale = 2, int $height = 40): string
    {
        $value = $this->normalize($type, $value);

        try {
            if ($type->isMatrix()) {
                return (new SvgWriter)->write($this->qrCode($value), options: [SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true])->getString();
            }

            return (new BarcodeGeneratorSVG)->getBarcode($value, $this->pictureType($type), $scale, $height);
        } catch (Throwable $e) {
            throw $this->translate($e, $value);
        }
    }

    /**
     * The binary PNG image.
     *
     * @throws BarcodeGenerationException
     */
    public function png(BarcodeType $type, string $value, int $scale = 2, int $height = 40): string
    {
        $value = $this->normalize($type, $value);

        try {
            if ($type->isMatrix()) {
                return (new PngWriter)->write($this->qrCode($value))->getString();
            }

            return (new BarcodeGeneratorPNG)->getBarcode($value, $this->pictureType($type), $scale, $height);
        } catch (Throwable $e) {
            throw $this->translate($e, $value);
        }
    }

    /**
     * PNG as a base64 data URI, ready for `<img src>` in Dompdf templates.
     *
     * @throws BarcodeGenerationException
     */
    public function pngDataUri(BarcodeType $type, string $value, int $scale = 2, int $height = 40): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png($type, $value, $scale, $height));
    }

    /**
     * Render in the requested output format: SVG markup or the binary PNG.
     *
     * @throws BarcodeGenerationException
     */
    public function render(BarcodeType $type, string $value, string $format, int $scale = 2, int $height = 40): string
    {
        return match (strtolower($format)) {
            self::FORMAT_SVG => $this->svg($type, $value, $scale, $height),
            self::FORMAT_PNG => $this->png($type, $value, $scale, $height),
            default => throw BarcodeGenerationException::because(BarcodeGenerationException::UNSUPPORTED_FORMAT, $format),
        };
    }

    private function normalizeEan(string $value, int $payloadLength): string
    {
        if (! ctype_digit($value)) {
            throw BarcodeGenerationException::because(BarcodeGenerationException::INVALID_CHARACTERS, $value);
        }

        $length = strlen($value);

        if ($length === $payloadLength) {
            return $value.$this->eanCheckDigit($value);
        }

        if ($length !== $payloadLength + 1) {
            throw BarcodeGenerationException::because(BarcodeGenerationException::INVALID_LENGTH, $value);
        }

        if ($this->eanCheckDigit(substr($value, 0, -1)) !== (int) substr($value, -1)) {
            throw BarcodeGenerationException::because(BarcodeGenerationException::INVALID_CHECKSUM, $value);
        }

        return $value;
    }

    private function normalizeCode128(string $value): string
    {
        if (! preg_match('/^[\x20-\x7E]+$/', $value)) {
            throw BarcodeGenerationException::because(BarcodeGenerationException::INVALID_CHARACTERS, $value);
        }

        if (strlen($value) > self::CODE128_MAX_LENGTH) {
            throw BarcodeGenerationException::because(BarcodeGenerationException::INVALID_LENGTH, $value);
        }

        return $value;
    }

    private function normalizeQr(string $value): string
    {
        if (mb_strlen($value) > self::QR_MAX_LENGTH) {
            throw BarcodeGenerationException::because(BarcodeGenerationException::INVALID_LENGTH, $value);
        }

        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            throw BarcodeGenerationException::because(BarcodeGenerationException::INVALID_CHARACTERS, $value);
        }

        return $value;
    }

    private function pictureType(BarcodeType $type): string
    {
        return match ($type) {
            BarcodeType::Ean13 => BarcodeGenerator::TYPE_EAN_13,
            BarcodeType::Ean8 => BarcodeGenerator::TYPE_EAN_8,
            BarcodeType::Code128 => BarcodeGenerator::TYPE_CODE_128,
            BarcodeType::QrCode => throw BarcodeGenerationException::because(BarcodeGenerationException::UNSUPPORTED_TYPE),
        };
    }

    private function qrCode(string $value): QrCode
    {
        return new QrCode(
            data: $value,
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: self::QR_PIXEL_SIZE,
            margin: 0,
            roundBlockSizeMode: RoundBlockSizeMode::Enlarge,
        );
    }

    /**
     * Map library exceptions onto the typed application exception.
     */
    private function translate(Throwable $e, string $value): BarcodeGenerationException
    {
        return match (true) {
            $e instanceof BarcodeGenerationException => $e,
            $e instanceof InvalidCharacterException => BarcodeGenerationException::because(BarcodeGenerationException::INVALID_CHARACTERS, $value),
            $e instanceof InvalidLengthException => BarcodeGenerationException::because(BarcodeGenerationException::INVALID_LENGTH, $value),
            $e instanceof InvalidCheckDigitException => BarcodeGenerationException::because(BarcodeGenerationException::INVALID_CHECKSUM, $value),
            $e instanceof BarcodeException => BarcodeGenerationException::because(BarcodeGenerationException::INVALID_CHARACTERS, $value),
            default => BarcodeGenerationException::because(BarcodeGenerationException::RENDERING_FAILED, $value),
        };
    }
}
