<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\Services;

use Modules\Platform\Identity\Domain\Enums\FieldVisibility;

/** Applies field policies to payloads; raw values of masked or hidden fields never leave this class. */
final class FieldMasker
{
    public const AMOUNT_MASK = '•••';

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, FieldVisibility>  $policies  field name => visibility
     * @param  array<string, string>  $kinds  field name => `iban` | `amount` (default amount)
     * @return array{data: array<string, mixed>, masked_fields: list<string>}
     */
    public function apply(array $payload, array $policies, array $kinds = []): array
    {
        $masked = [];

        foreach ($policies as $field => $visibility) {
            if (! array_key_exists($field, $payload) || $visibility === FieldVisibility::Visible) {
                continue;
            }

            if ($visibility === FieldVisibility::Hidden) {
                unset($payload[$field]);

                continue;
            }

            $payload[$field] = ($kinds[$field] ?? 'amount') === 'iban'
                ? $this->maskIban($payload[$field])
                : self::AMOUNT_MASK;
            $masked[] = $field;
        }

        return ['data' => $payload, 'masked_fields' => $masked];
    }

    /** Keeps the two country letters and the last four characters, grouped by four, e.g. `CH** **** **** **** *123 4`. */
    public function maskIban(mixed $iban): string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', is_scalar($iban) ? (string) $iban : ''));

        if (strlen($compact) < 8 || preg_match('/^[A-Z]{2}/', $compact) !== 1) {
            return self::AMOUNT_MASK;
        }

        $masked = substr($compact, 0, 2).str_repeat('*', strlen($compact) - 6).substr($compact, -4);

        return trim(chunk_split($masked, 4, ' '));
    }
}
