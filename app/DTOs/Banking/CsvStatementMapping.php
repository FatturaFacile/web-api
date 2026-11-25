<?php

declare(strict_types=1);

namespace App\DTOs\Banking;

/**
 * Deterministic description of a bank's CSV export: delimiter, date and number formats and which column carries which
 * field. Columns are header names (case-insensitive) when the file has a header row, otherwise 1-based positions.
 *
 * Either `amount` (signed: negative is a debit) or `debit` and `credit` must be mapped.
 */
final readonly class CsvStatementMapping
{
    /**
     * @var list<string>
     */
    public const FIELDS = ['booking_date', 'value_date', 'amount', 'debit', 'credit', 'fee', 'description', 'reference', 'qr_reference', 'counterpart_name', 'counterpart_iban', 'entry_reference'];

    /**
     * @param  array<string, string|int>  $columns  field => header name or 1-based position
     */
    public function __construct(
        public array $columns,
        public string $accountIban,
        public string $currency = 'EUR',
        public string $delimiter = ';',
        public bool $hasHeader = true,
        public string $dateFormat = 'd/m/Y',
        public string $decimalSeparator = ',',
        public ?string $thousandsSeparator = '.',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            columns: array_filter((array) ($data['columns'] ?? []), static fn (mixed $value): bool => $value !== null && $value !== ''),
            accountIban: strtoupper((string) preg_replace('/\s+/', '', (string) ($data['account_iban'] ?? ''))),
            currency: strtoupper((string) ($data['currency'] ?? 'EUR')),
            delimiter: (string) ($data['delimiter'] ?? ';'),
            hasHeader: (bool) ($data['has_header'] ?? true),
            dateFormat: (string) ($data['date_format'] ?? 'd/m/Y'),
            decimalSeparator: (string) ($data['decimal_separator'] ?? ','),
            thousandsSeparator: isset($data['thousands_separator']) && $data['thousands_separator'] !== '' ? (string) $data['thousands_separator'] : null,
        );
    }
}
