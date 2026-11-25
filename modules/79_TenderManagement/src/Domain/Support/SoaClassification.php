<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\Support;

/**
 * The classifications of an SOA attestation (DPR 207/2010 Art. 61) ordered by the amount of works they qualify for.
 */
final class SoaClassification
{
    /** @var list<string> */
    public const array Order = ['I', 'II', 'III', 'III-bis', 'IV', 'IV-bis', 'V', 'VI', 'VII', 'VIII'];

    /**
     * @return list<string>
     */
    public static function categories(): array
    {
        $general = array_map(static fn (int $n): string => 'OG'.$n, range(1, 13));
        $specialist = array_map(static fn (int $n): string => 'OS'.$n, range(1, 35));

        return [...$general, ...$specialist];
    }

    /**
     * Position of a classification (0 = I); null when unknown.
     */
    public static function rank(string $classification): ?int
    {
        $index = array_search(strtoupper(trim($classification)) === 'III-BIS' ? 'III-bis' : (strtoupper(trim($classification)) === 'IV-BIS' ? 'IV-bis' : strtoupper(trim($classification))), self::Order, true);

        return $index === false ? null : (int) $index;
    }

    /**
     * Whether the held classification covers the required one.
     */
    public static function covers(string $held, string $required): bool
    {
        $heldRank = self::rank($held);
        $requiredRank = self::rank($required);

        return $heldRank !== null && $requiredRank !== null && $heldRank >= $requiredRank;
    }
}
