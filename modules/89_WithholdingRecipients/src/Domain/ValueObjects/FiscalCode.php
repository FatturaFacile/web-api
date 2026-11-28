<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Italian personal Codice Fiscale (16 characters): carries year, month and day of birth, the day being increased by 40 for
 * women. The century is not encoded, so it is resolved against the date the age is asked for.
 */
final readonly class FiscalCode
{
    private const string MONTHS = 'ABCDEHLMPRST';

    private function __construct(public string $value) {}

    public static function tryFrom(?string $value): ?self
    {
        $normalized = strtoupper(trim((string) $value));

        if (preg_match('/^[A-Z]{6}\d{2}['.self::MONTHS.']\d{2}[A-Z]\d{3}[A-Z]$/', $normalized) !== 1) {
            return null;
        }

        return new self($normalized);
    }

    /**
     * Birth date, with the century chosen so that the person is at least 14 years old on $reference and as young as possible.
     */
    public function birthDate(CarbonImmutable $reference): ?CarbonImmutable
    {
        $year = (int) substr($this->value, 6, 2);
        $month = (int) strpos(self::MONTHS, $this->value[8]) + 1;
        $day = (int) substr($this->value, 9, 2);
        $day = $day > 40 ? $day - 40 : $day;
        $century = (int) $reference->format('Y') - ((int) $reference->format('Y') % 100);

        foreach ([$century + $year, $century - 100 + $year] as $candidateYear) {
            if (! checkdate($month, $day, $candidateYear)) {
                continue;
            }

            $birth = CarbonImmutable::create($candidateYear, $month, $day)->startOfDay();

            if ($birth->lessThanOrEqualTo($reference->subYears(14))) {
                return $birth;
            }
        }

        return null;
    }
}
