<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Providers;

use Modules\OcrExtraction\Domain\Contracts\OcrVisionProviderInterface;
use Modules\OcrExtraction\Domain\Enums\DocumentCategory;
use Modules\OcrExtraction\Domain\Exceptions\OcrProviderException;
use Modules\OcrExtraction\Domain\ValueObjects\BoundingBoxDto;
use Modules\OcrExtraction\Domain\ValueObjects\ExtractedPayloadDto;
use Modules\OcrExtraction\Domain\ValueObjects\OcrExtractionRequest;
use Modules\OcrExtraction\Domain\ValueObjects\OcrExtractionResult;

/**
 * Shared part of the engines that answer with JSON: the instruction sent to the model and the strict reading of its
 * answer into the payload schema and the bounding boxes.
 */
abstract class AbstractVisionAdapter implements OcrVisionProviderInterface
{
    /**
     * Instruction that makes a model answer in the schema of ExtractedPayloadDto.
     */
    protected function prompt(OcrExtractionRequest $request): string
    {
        $kind = match ($request->category) {
            DocumentCategory::VendorInvoice => 'a vendor invoice',
            DocumentCategory::ExpenseReceipt => 'an expense receipt',
            DocumentCategory::TransportDdt => 'a transport document (DDT / Lieferschein / bon de livraison)',
            DocumentCategory::VendorQuote => 'a vendor quotation',
        };

        $skip = $request->skipFieldKeys === []
            ? ''
            : "\nThe fields ".implode(', ', $request->skipFieldKeys).' are already known: answer null for them.';

        return "You read {$kind} in Italian, German, French or English. Extract the data as JSON that follows the given schema exactly. "
            .'Dates as YYYY-MM-DD, numbers without thousands separators, the currency as an ISO code, the Swiss UID as CHE-123.456.789. '
            .'For each line give description, quantity, unit_price, net_amount and the VAT rate in percent; list every VAT rate with its net and VAT amounts in vat_breakdown. '
            .'Use null for anything you cannot read; never invent values. field_confidence maps each extracted field key '
            .'(vendor.name, header.document_number, header.issue_date, totals.gross, lines[0].unit_price, ...) to your confidence from 0 to 100, '
            .'and bounding_boxes maps the same keys to the region of the page it was read from as ratios of the page size (page starting at 1, x, y, width, height).'
            .$skip;
    }

    /**
     * Reads the JSON answer of a model into a result, ignoring unknown keys and invalid boxes.
     *
     * @throws OcrProviderException when the answer is not a JSON object
     */
    protected function readAnswer(string $answer, int $tokensUsed): OcrExtractionResult
    {
        $answer = trim($answer);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $answer, $fenced)) {
            $answer = $fenced[1];
        }

        $data = json_decode($answer, true);

        if (! is_array($data)) {
            throw new OcrProviderException('The engine did not answer with a JSON object.');
        }

        $boxes = [];

        foreach ((array) ($data['bounding_boxes'] ?? []) as $key => $box) {
            $parsed = is_string($key) && is_array($box) ? BoundingBoxDto::tryFromArray($box) : null;

            if ($parsed !== null) {
                $boxes[$key] = $parsed;
            }
        }

        return new OcrExtractionResult(ExtractedPayloadDto::fromArray($data), $boxes, $this->type(), $tokensUsed);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function setting(array $settings, string $key, string $default): string
    {
        $value = $settings[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws OcrProviderException when the setting is missing
     */
    protected function requiredSetting(array $settings, string $key): string
    {
        $value = $this->setting($settings, $key, '');

        if ($value === '') {
            throw new OcrProviderException("The OCR provider setting '{$key}' is not configured.");
        }

        return $value;
    }
}
