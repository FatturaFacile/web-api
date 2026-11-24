<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\OcrExtraction\Domain\Contracts\OcrVisionProviderInterface;
use Modules\OcrExtraction\Domain\Enums\OcrProviderType;
use Modules\OcrExtraction\Domain\Exceptions\OcrProviderException;
use Modules\OcrExtraction\Domain\ValueObjects\BoundingBoxDto;
use Modules\OcrExtraction\Domain\ValueObjects\ExtractedPayloadDto;
use Modules\OcrExtraction\Domain\ValueObjects\OcrExtractionRequest;
use Modules\OcrExtraction\Domain\ValueObjects\OcrExtractionResult;

/**
 * Azure Document Intelligence prebuilt-invoice model (cloud). Only used when the tenant consented to external AI providers.
 * The service is asynchronous: the document is submitted, then the operation is polled until it ends. Polygons in page
 * units are converted to page ratios.
 */
final class AzureDocumentIntelligenceAdapter implements OcrVisionProviderInterface
{
    private const string ApiVersion = '2024-11-30';

    public function type(): OcrProviderType
    {
        return OcrProviderType::AzureDocumentIntelligence;
    }

    public function extract(OcrExtractionRequest $request): OcrExtractionResult
    {
        $endpoint = rtrim((string) ($request->settings['endpoint'] ?? ''), '/');
        $key = (string) ($request->settings['api_key'] ?? '');

        if ($endpoint === '' || $key === '') {
            throw new OcrProviderException("The OCR provider settings 'endpoint' and 'api_key' are not configured.");
        }

        $analysis = $this->analyze($endpoint, $key, $request->contents);
        $result = (array) ($analysis['analyzeResult'] ?? []);
        $fields = (array) ($result['documents'][0]['fields'] ?? []);

        if ($fields === []) {
            throw new OcrProviderException('Azure Document Intelligence found no invoice in the document.');
        }

        return $this->map($fields, (array) ($result['pages'] ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    private function analyze(string $endpoint, string $key, string $contents): array
    {
        $timeout = (int) config('ocr_extraction.timeout_seconds', 120);

        try {
            $submit = Http::withHeaders(['Ocp-Apim-Subscription-Key' => $key])->timeout($timeout)
                ->post("{$endpoint}/documentintelligence/documentModels/prebuilt-invoice:analyze?api-version=".self::ApiVersion, ['base64Source' => base64_encode($contents)]);

            if ($submit->status() !== 202 || $submit->header('Operation-Location') === '') {
                throw new OcrProviderException('Azure Document Intelligence rejected the document (HTTP '.$submit->status().').');
            }

            $location = $submit->header('Operation-Location');
            $attempts = (int) config('ocr_extraction.azure.poll_attempts', 30);

            for ($attempt = 0; $attempt < $attempts; $attempt++) {
                $poll = Http::withHeaders(['Ocp-Apim-Subscription-Key' => $key])->timeout($timeout)->get($location);
                $status = (string) $poll->json('status', '');

                if ($status === 'succeeded') {
                    return (array) $poll->json();
                }

                if ($status === 'failed') {
                    throw new OcrProviderException('Azure Document Intelligence failed to analyze the document.');
                }

                usleep((int) config('ocr_extraction.azure.poll_interval_ms', 1000) * 1000);
            }
        } catch (ConnectionException $exception) {
            throw new OcrProviderException('Azure Document Intelligence is not reachable: '.$exception->getMessage(), 0, $exception);
        }

        throw new OcrProviderException('Azure Document Intelligence did not finish in time.');
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, mixed>  $pages
     */
    private function map(array $fields, array $pages): OcrExtractionResult
    {
        $dimensions = [];

        foreach ($pages as $page) {
            $dimensions[(int) ($page['pageNumber'] ?? 1)] = [(float) ($page['width'] ?? 0), (float) ($page['height'] ?? 0)];
        }

        $confidence = [];
        $boxes = [];
        $read = function (string $key, mixed $field) use (&$confidence, &$boxes, $dimensions): mixed {
            if (! is_array($field)) {
                return null;
            }

            $value = $field['valueString'] ?? $field['valueDate'] ?? $field['valueNumber'] ?? $field['valueCurrency']['amount'] ?? $field['content'] ?? null;

            if ($value !== null) {
                $confidence[$key] = round((float) ($field['confidence'] ?? 0) * 100, 2);
                $box = $this->box($field, $dimensions);

                if ($box !== null) {
                    $boxes[$key] = $box;
                }
            }

            return $value;
        };

        $taxId = (string) ($read('vendor.vat_number', $fields['VendorTaxId'] ?? null) ?? '');
        $isUid = stripos($taxId, 'CHE') === 0;

        $lines = [];

        foreach ((array) ($fields['Items']['valueArray'] ?? []) as $index => $item) {
            $object = (array) ($item['valueObject'] ?? []);
            $lines[] = [
                'description' => (string) $read("lines[{$index}].description", $object['Description'] ?? null),
                'quantity' => $read("lines[{$index}].quantity", $object['Quantity'] ?? null),
                'unit_price' => $read("lines[{$index}].unit_price", $object['UnitPrice'] ?? null),
                'net_amount' => $read("lines[{$index}].net_amount", $object['Amount'] ?? null),
                'vat_rate' => $read("lines[{$index}].vat_rate", $object['TaxRate'] ?? null),
            ];
        }

        $breakdown = [];

        foreach ((array) ($fields['TaxDetails']['valueArray'] ?? []) as $detail) {
            $object = (array) ($detail['valueObject'] ?? []);
            $breakdown[] = [
                'rate' => $object['Rate']['valueString'] ?? $object['Rate']['content'] ?? null,
                'net' => $object['NetAmount']['valueCurrency']['amount'] ?? 0,
                'vat' => $object['Amount']['valueCurrency']['amount'] ?? 0,
            ];
        }

        $order = $read('header.order_reference', $fields['PurchaseOrder'] ?? null);
        $payload = ExtractedPayloadDto::fromArray([
            'vendor' => [
                'name' => $read('vendor.name', $fields['VendorName'] ?? null),
                'vat_number' => $isUid ? null : $taxId,
                'uid' => $isUid ? $taxId : null,
                'iban' => $read('vendor.iban', $fields['PaymentDetails']['valueArray'][0]['valueObject']['IBAN'] ?? null),
            ],
            'document' => [
                'number' => $read('header.document_number', $fields['InvoiceId'] ?? null),
                'issue_date' => $read('header.issue_date', $fields['InvoiceDate'] ?? null),
                'due_date' => $read('header.due_date', $fields['DueDate'] ?? null),
                'currency' => $fields['InvoiceTotal']['valueCurrency']['currencyCode'] ?? null,
            ],
            'order_references' => $order !== null ? [(string) $order] : [],
            'totals' => [
                'net' => $read('totals.net', $fields['SubTotal'] ?? null),
                'vat' => $read('totals.vat', $fields['TotalTax'] ?? null),
                'gross' => $read('totals.gross', $fields['InvoiceTotal'] ?? null),
            ],
            'vat_breakdown' => $breakdown,
            'lines' => $lines,
            'field_confidence' => $confidence,
        ]);

        return new OcrExtractionResult($payload, $boxes, $this->type(), 0);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<int, array{0: float, 1: float}>  $dimensions
     */
    private function box(array $field, array $dimensions): ?BoundingBoxDto
    {
        $region = $field['boundingRegions'][0] ?? null;
        $polygon = is_array($region) ? ($region['polygon'] ?? []) : [];
        $page = (int) ($region['pageNumber'] ?? 1);

        if (count($polygon) < 8 || ! isset($dimensions[$page]) || $dimensions[$page][0] <= 0 || $dimensions[$page][1] <= 0) {
            return null;
        }

        $xs = [$polygon[0], $polygon[2], $polygon[4], $polygon[6]];
        $ys = [$polygon[1], $polygon[3], $polygon[5], $polygon[7]];
        [$width, $height] = $dimensions[$page];

        return BoundingBoxDto::tryFromArray([
            'page' => $page,
            'x' => min($xs) / $width,
            'y' => min($ys) / $height,
            'width' => (max($xs) - min($xs)) / $width,
            'height' => (max($ys) - min($ys)) / $height,
        ]);
    }
}
