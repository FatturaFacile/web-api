<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * Dynamic data masking for sensitive values returned by the API unless the caller holds `privacy.pii.unmask`.
 */
final class PiiMasker
{
    public const PERMISSION = 'privacy.pii.unmask';

    public function iban(string $value): string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $value));

        if (strlen($compact) < 8) {
            return $this->mask($value, 0, 0);
        }

        $middle = implode(' ', array_fill(0, 3, '****'));

        return substr($compact, 0, 4).' '.$middle.' '.substr($compact, -4);
    }

    /**
     * Tax / identity numbers: keeps the first six characters (e.g. RSSMRA**********), hides the rest.
     */
    public function taxId(string $value): string
    {
        return $this->mask($value, 6, 0);
    }

    public function email(string $value): string
    {
        $at = strrpos($value, '@');

        if ($at === false || $at === 0) {
            return $this->mask($value, 1, 0);
        }

        return substr($value, 0, 1).str_repeat('*', max(1, $at - 1)).substr($value, $at);
    }

    public function phone(string $value): string
    {
        return $this->mask($value, 0, 3);
    }

    public function mask(string $value, int $keepStart, int $keepEnd): string
    {
        $length = mb_strlen($value);

        if ($length <= $keepStart + $keepEnd) {
            return str_repeat('*', max($length, 1));
        }

        return mb_substr($value, 0, $keepStart)
            .str_repeat('*', $length - $keepStart - $keepEnd)
            .($keepEnd > 0 ? mb_substr($value, -$keepEnd) : '');
    }
}
