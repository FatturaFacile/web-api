<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\PaymentTerm;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class PaymentTermScheduler
{
    /**
     * Split an amount into due dates according to the payment term (single due date or installments).
     * The last installment absorbs the rounding remainder so the schedule always sums to the amount.
     *
     * @return array{installments: array<int, array{sequence: int, due_date: string, amount: float}>, early_discount: ?array{until: string, percent: float, amount: float}}
     */
    public function schedule(PaymentTerm $term, float $amount, CarbonInterface $documentDate): array
    {
        $base = CarbonImmutable::instance($documentDate)->startOfDay();
        $rows = $term->installments()->orderBy('sequence')->get();

        $parts = $rows->isEmpty()
            ? [['sequence' => 1, 'percent' => 100.0, 'days' => $term->days]]
            : $rows->map(fn ($row): array => ['sequence' => $row->sequence, 'percent' => (float) $row->percent, 'days' => $row->days])->all();

        $installments = [];
        $allocated = 0.0;
        $lastIndex = count($parts) - 1;

        foreach ($parts as $index => $part) {
            $due = $base->addDays($part['days']);
            if ($term->end_of_month) {
                $due = $due->endOfMonth();
            }

            $share = $index === $lastIndex ? round($amount - $allocated, 2) : round($amount * $part['percent'] / 100, 2);
            $allocated += $share;

            $installments[] = ['sequence' => $part['sequence'], 'due_date' => $due->toDateString(), 'amount' => $share];
        }

        $discount = null;
        if ($term->discount_percent !== null && $term->discount_days !== null) {
            $discount = [
                'until' => $base->addDays($term->discount_days)->toDateString(),
                'percent' => (float) $term->discount_percent,
                'amount' => round($amount * (float) $term->discount_percent / 100, 2),
            ];
        }

        return ['installments' => $installments, 'early_discount' => $discount];
    }
}
