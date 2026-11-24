<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Domain\Services;

use InvalidArgumentException;
use Modules\AnalyticalAccounting\Support\Money;

/**
 * Splits the amount of a posting line over one or more analytical allocation vectors, either by percentage (summing to
 * exactly 100) or by exact amount (summing to the line amount), with deterministic Largest Remainder rounding.
 */
final class AllocationVectorSplitter
{
    /**
     * @param  list<array<string, mixed>>  $vectors
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    public function split(int $amountCents, array $vectors): array
    {
        if ($vectors === []) {
            throw new InvalidArgumentException('At least one allocation vector is required.');
        }
        $byAmount = array_filter($vectors, static fn (array $v): bool => isset($v['amount']) && ! isset($v['percentage']));
        $byPercent = array_filter($vectors, static fn (array $v): bool => isset($v['percentage']));
        if ($byAmount !== [] && $byPercent !== []) {
            throw new InvalidArgumentException('Allocation vectors must all use percentages or all use amounts.');
        }
        if (count($byAmount) + count($byPercent) !== count($vectors)) {
            throw new InvalidArgumentException('Every allocation vector needs a percentage or an amount.');
        }

        if ($byAmount !== []) {
            $sum = 0;
            foreach ($vectors as $i => $vector) {
                $cents = Money::toCents((string) $vector['amount']);
                $vectors[$i]['amount_cents'] = $cents;
                $sum += $cents;
            }
            if ($sum !== $amountCents) {
                throw new InvalidArgumentException('The vector amounts must add up to the line amount.');
            }
            foreach ($vectors as $i => $vector) {
                $vectors[$i]['split_percentage'] = $amountCents === 0 ? '0.0000' : bcmul(bcdiv((string) $vector['amount_cents'], (string) $amountCents, 10), '100', 4);
            }

            return $vectors;
        }

        $weights = [];
        $total = '0';
        foreach ($vectors as $i => $vector) {
            $weights[$i] = bcadd((string) $vector['percentage'], '0', 4);
            $total = bcadd($total, $weights[$i], 4);
        }
        if (bccomp($total, '100', 4) !== 0) {
            throw new InvalidArgumentException('The vector percentages must add up to 100.');
        }
        $shares = Money::allocate($amountCents, $weights);
        foreach ($vectors as $i => $vector) {
            $vectors[$i]['amount_cents'] = $shares[$i];
            $vectors[$i]['split_percentage'] = $weights[$i];
        }

        return $vectors;
    }
}
