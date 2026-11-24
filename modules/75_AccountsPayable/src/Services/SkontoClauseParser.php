<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Services;

use Modules\AccountsPayable\DTOs\SkontoTerms;

/**
 * Reads the cash-discount terms out of the payment clause of a supplier contract or invoice, in Italian, German, French
 * or English: `30 gg data fattura, 2% skonto entro 10 gg`, `2% Skonto innerhalb 10 Tagen, netto 30 Tage`, `2/10 net 30`.
 * The smaller number of days is the discount deadline, the larger one the net term.
 */
final class SkontoClauseParser
{
    private const string DayUnits = 'gg|giorni|giorno|days?|tage[n]?|tg|jours?|jrs?|g|d|j';

    /**
     * @return SkontoTerms|null null when the clause carries no discount
     */
    public function parse(string $clause, ?string $reference = null): ?SkontoTerms
    {
        $text = mb_strtolower(trim($clause));

        if ($text === '') {
            return null;
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*\/\s*(\d+)\s*[,;]?\s*(?:net|n|netto|netto\s*a)\s*\/?\s*(\d+)/u', $text, $matches) === 1) {
            return $this->terms($matches[1], (int) $matches[2], (int) $matches[3], $reference);
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*%/u', $text, $percent) !== 1) {
            return null;
        }

        preg_match_all('/(?<![\d.,])(\d{1,3})\s*(?:'.self::DayUnits.')(?![a-z])/u', $text, $days);
        $days = array_map('intval', $days[1]);

        if ($days === []) {
            return null;
        }

        return $this->terms($percent[1], min($days), count($days) > 1 ? max($days) : null, $reference);
    }

    private function terms(string $percentage, int $discountDays, ?int $netDays, ?string $reference): ?SkontoTerms
    {
        $terms = new SkontoTerms(
            percentage: bcadd(str_replace(',', '.', $percentage), '0', 2),
            days: $discountDays,
            netDays: $netDays !== null && $netDays > $discountDays ? $netDays : null,
            source: SkontoTerms::SourceSupplierAgreement,
            reference: $reference,
        );

        return $terms->isUsable() ? $terms : null;
    }
}
