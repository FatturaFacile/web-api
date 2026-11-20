<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Extraction;

use App\Contracts\SwissQrBill\SwissQrBillParserInterface;
use App\Exceptions\QrBillParseException;
use Modules\OcrExtraction\Domain\ValueObjects\ExtractedPayloadDto;

/**
 * Decodes the Swiss QR-bill (SPC 0200) printed on a PDF or image with the QR-bill parser of the invoicing module. The
 * payment fields it holds (IBAN or QR-IBAN, creditor, currency, amount, QRR/SCOR reference) are exact; line items and the
 * VAT split are not in the QR code and are read by the OCR engine.
 */
final readonly class SwissQrBillExtractor
{
    /**
     * Field keys the QR-bill is authoritative for.
     *
     * @var list<string>
     */
    public const array FieldKeys = ['vendor.name', 'vendor.iban', 'vendor.qr_iban', 'vendor.vat_number', 'header.currency', 'header.qr_reference', 'header.qr_reference_type', 'totals.gross'];

    public function __construct(private SwissQrBillParserInterface $parser) {}

    /**
     * @return array{payload: ExtractedPayloadDto, field_keys: list<string>, raw: string}|null null when the file holds no readable QR-bill
     */
    public function extract(string $contents): ?array
    {
        try {
            $bill = $this->parser->parseFile($contents);
        } catch (QrBillParseException) {
            return null;
        }

        $referenceType = $bill->referenceType === 'NON' ? null : $bill->referenceType;
        $vatNumber = $bill->billingInformation['vat_number'] ?? null;
        $payload = ExtractedPayloadDto::fromArray([
            'vendor' => [
                'name' => $bill->creditor['name'] ?? null,
                'iban' => $bill->isQrIban ? null : $bill->iban,
                'qr_iban' => $bill->isQrIban ? $bill->iban : null,
                'vat_number' => $vatNumber,
                'uid' => $vatNumber !== null && stripos($vatNumber, 'CHE') === 0 ? $vatNumber : null,
            ],
            'document' => [
                'number' => $bill->billingInformation['invoice_number'] ?? null,
                'issue_date' => $bill->billingInformation['invoice_date'] ?? null,
                'currency' => $bill->currency,
                'qr_reference' => $bill->reference,
                'qr_reference_type' => $referenceType,
            ],
            'totals' => ['gross' => $bill->amount],
        ]);

        $keys = $payload->filledFieldKeys();
        $confidence = [];

        foreach ($keys as $key) {
            $confidence[$key] = 100.0;
        }

        return [
            'payload' => new ExtractedPayloadDto($payload->vendor, $payload->document, [], $payload->totals, [], [], $confidence),
            'field_keys' => $keys,
            'raw' => $bill->payload,
        ];
    }
}
