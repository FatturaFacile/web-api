<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Normalized content of an extracted document, whatever engine produced it. Provider answers pass through fromArray(),
 * so the stored JSON always has the same shape: vendor identifiers, header, totals, VAT breakdown, lines, and a
 * confidence (0–100) per field keyed like the bounding boxes (`header.document_number`, `lines[0].unit_price`).
 */
final readonly class ExtractedPayloadDto
{
    private const array VendorKeys = ['name', 'uid', 'vat_number', 'fiscal_code', 'iban', 'qr_iban'];

    private const array DocumentKeys = ['number', 'issue_date', 'due_date', 'currency', 'qr_reference', 'qr_reference_type'];

    private const array TotalKeys = ['net', 'vat', 'gross'];

    /**
     * @param  array<string, string|null>  $vendor
     * @param  array<string, string|null>  $document
     * @param  list<string>  $orderReferences  purchase order numbers found on the document
     * @param  array<string, float|null>  $totals
     * @param  list<array{rate: float, net: float, vat: float}>  $vatBreakdown
     * @param  list<array{description: string, quantity: float|null, unit_price: float|null, net_amount: float|null, vat_rate: float|null}>  $lines
     * @param  array<string, float>  $fieldConfidence
     */
    public function __construct(
        public array $vendor,
        public array $document,
        public array $orderReferences,
        public array $totals,
        public array $vatBreakdown,
        public array $lines,
        public array $fieldConfidence,
    ) {}

    /**
     * Normalizes the loosely typed answer of an engine (numbers as "1'234.50" or "1.234,50", dates as 31.12.2026).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $vendor = self::strings((array) ($data['vendor'] ?? []), self::VendorKeys);
        $vendor['uid'] = self::normalizeUid($vendor['uid']);
        $vendor['vat_number'] = self::compact($vendor['vat_number']);
        $vendor['iban'] = self::compact($vendor['iban']);
        $vendor['qr_iban'] = self::compact($vendor['qr_iban']);

        $document = self::strings((array) ($data['document'] ?? []), self::DocumentKeys);
        $document['issue_date'] = self::toDate($document['issue_date']);
        $document['due_date'] = self::toDate($document['due_date']);
        $document['currency'] = $document['currency'] !== null ? strtoupper(substr($document['currency'], 0, 3)) : null;

        $references = [];

        foreach ((array) ($data['order_references'] ?? $data['document']['order_references'] ?? []) as $reference) {
            if (is_scalar($reference) && trim((string) $reference) !== '') {
                $references[] = trim((string) $reference);
            }
        }

        $totals = [];

        foreach (self::TotalKeys as $key) {
            $totals[$key] = self::toFloat(((array) ($data['totals'] ?? []))[$key] ?? null);
        }

        $breakdown = [];

        foreach ((array) ($data['vat_breakdown'] ?? []) as $row) {
            $row = (array) $row;
            $rate = self::toFloat($row['rate'] ?? null);

            if ($rate !== null) {
                $breakdown[] = ['rate' => $rate, 'net' => self::toFloat($row['net'] ?? null) ?? 0.0, 'vat' => self::toFloat($row['vat'] ?? null) ?? 0.0];
            }
        }

        $lines = [];

        foreach ((array) ($data['lines'] ?? []) as $row) {
            $row = (array) $row;
            $description = trim((string) ($row['description'] ?? ''));

            if ($description === '') {
                continue;
            }

            $lines[] = [
                'description' => $description,
                'quantity' => self::toFloat($row['quantity'] ?? null),
                'unit_price' => self::toFloat($row['unit_price'] ?? null),
                'net_amount' => self::toFloat($row['net_amount'] ?? null),
                'vat_rate' => self::toFloat($row['vat_rate'] ?? null),
            ];
        }

        $confidence = [];

        foreach ((array) ($data['field_confidence'] ?? []) as $key => $value) {
            if (is_string($key) && is_numeric($value)) {
                $confidence[$key] = round(min(100.0, max(0.0, (float) $value)), 2);
            }
        }

        return new self($vendor, $document, array_values(array_unique($references)), $totals, $breakdown, $lines, $confidence);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'vendor' => $this->vendor,
            'document' => $this->document,
            'order_references' => $this->orderReferences,
            'totals' => $this->totals,
            'vat_breakdown' => $this->vatBreakdown,
            'lines' => $this->lines,
            'field_confidence' => $this->fieldConfidence,
        ];
    }

    /**
     * The same payload with confidence 100 on every field the structured source (XML or QR-bill) supplied, overriding what
     * the OCR engine read for those fields.
     *
     * @param  list<string>  $fieldKeys  keys (as in fieldConfidence) the deterministic payload is authoritative for
     */
    public function overlay(self $deterministic, array $fieldKeys): self
    {
        $vendor = $this->vendor;
        $document = $this->document;
        $totals = $this->totals;
        $confidence = $this->fieldConfidence;

        foreach ($fieldKeys as $key) {
            [$section, $name] = explode('.', $key, 2);

            switch ($section) {
                case 'vendor':
                    $vendor[$name] = $deterministic->vendor[$name] ?? null;

                    break;
                case 'header':
                    $name = self::headerName($name);
                    $document[$name] = $deterministic->document[$name] ?? null;

                    break;
                case 'totals':
                    $totals[$name] = $deterministic->totals[$name] ?? null;

                    break;
            }

            $confidence[$key] = 100.0;
        }

        return new self($vendor, $document, $this->orderReferences, $totals, $this->vatBreakdown, $this->lines, $confidence);
    }

    /**
     * Keys of the fields that hold a value, in the shape used by fieldConfidence and the bounding boxes.
     *
     * @return list<string>
     */
    public function filledFieldKeys(): array
    {
        $keys = [];

        foreach ($this->vendor as $name => $value) {
            if ($value !== null && $value !== '') {
                $keys[] = 'vendor.'.$name;
            }
        }

        foreach ($this->document as $name => $value) {
            if ($value !== null && $value !== '') {
                $keys[] = 'header.'.self::headerKey($name);
            }
        }

        foreach ($this->totals as $name => $value) {
            if ($value !== null) {
                $keys[] = 'totals.'.$name;
            }
        }

        return $keys;
    }

    /**
     * Mean confidence of the fields that hold a value, 0 when the engine reported none.
     */
    public function overallConfidence(): float
    {
        if ($this->fieldConfidence === []) {
            return 0.0;
        }

        return round(array_sum($this->fieldConfidence) / count($this->fieldConfidence), 2);
    }

    /**
     * JSON Schema an engine must follow (Ollama `format`, OpenAI/Gemini response schema, LayoutLM service contract).
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $nullableNumber = ['type' => ['number', 'null']];
        $object = static fn (array $keys, array $type): array => ['type' => 'object', 'properties' => array_fill_keys($keys, $type), 'required' => $keys];

        return [
            'type' => 'object',
            'properties' => [
                'vendor' => $object(self::VendorKeys, $nullableString),
                'document' => $object(self::DocumentKeys, $nullableString),
                'order_references' => ['type' => 'array', 'items' => ['type' => 'string']],
                'totals' => $object(self::TotalKeys, $nullableNumber),
                'vat_breakdown' => ['type' => 'array', 'items' => $object(['rate', 'net', 'vat'], ['type' => 'number'])],
                'lines' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['description' => ['type' => 'string'], 'quantity' => $nullableNumber, 'unit_price' => $nullableNumber, 'net_amount' => $nullableNumber, 'vat_rate' => $nullableNumber],
                    'required' => ['description', 'quantity', 'unit_price', 'net_amount', 'vat_rate'],
                ]],
                'field_confidence' => ['type' => 'object', 'additionalProperties' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100]],
                'bounding_boxes' => ['type' => 'object', 'additionalProperties' => [
                    'type' => 'object',
                    'properties' => ['page' => ['type' => 'integer'], 'x' => ['type' => 'number'], 'y' => ['type' => 'number'], 'width' => ['type' => 'number'], 'height' => ['type' => 'number']],
                    'required' => ['page', 'x', 'y', 'width', 'height'],
                ]],
            ],
            'required' => ['vendor', 'document', 'totals', 'vat_breakdown', 'lines', 'field_confidence'],
        ];
    }

    /**
     * Parses a decimal in the formats seen on Swiss, Italian and German documents.
     */
    public static function toFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 4);
        }

        if (! is_string($value)) {
            return null;
        }

        $text = preg_replace("/[^0-9.,'’\\-]/u", '', $value) ?? '';
        $text = str_replace(["'", '’'], '', $text);

        if ($text === '' || ! preg_match('/\d/', $text)) {
            return null;
        }

        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');

        if ($comma !== false && $dot !== false) {
            $text = $comma > $dot ? str_replace(',', '.', str_replace('.', '', $text)) : str_replace(',', '', $text);
        } elseif ($comma !== false) {
            $text = strlen($text) - $comma - 1 <= 2 ? str_replace(',', '.', $text) : str_replace(',', '', $text);
        }

        return is_numeric($text) ? round((float) $text, 4) : null;
    }

    /**
     * Normalizes 31.12.2026, 31/12/2026, 20261231 and ISO dates to Y-m-d.
     */
    public static function toDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        foreach (['Y-m-d', 'Y-m-d\TH:i:sP', 'd.m.Y', 'd/m/Y', 'd-m-Y', 'Ymd', 'd.m.y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date !== null && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private static function normalizeUid(?string $uid): ?string
    {
        if ($uid === null) {
            return null;
        }

        if (preg_match('/CHE[-\s.]?(\d{3})[.\s]?(\d{3})[.\s]?(\d{3})/i', $uid, $match)) {
            return sprintf('CHE-%s.%s.%s', $match[1], $match[2], $match[3]);
        }

        return $uid === '' ? null : $uid;
    }

    private static function compact(?string $value): ?string
    {
        $value = $value === null ? '' : strtoupper(preg_replace('/[\s.]/', '', $value) ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, string|null>
     */
    private static function strings(array $data, array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            $result[$key] = is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
        }

        return $result;
    }

    private static function headerKey(string $documentKey): string
    {
        return match ($documentKey) {
            'number' => 'document_number',
            default => $documentKey,
        };
    }

    private static function headerName(string $headerKey): string
    {
        return $headerKey === 'document_number' ? 'number' : $headerKey;
    }
}
