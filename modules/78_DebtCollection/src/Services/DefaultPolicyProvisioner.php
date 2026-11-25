<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use Modules\DebtCollection\Models\Policy;

/**
 * Gives a company that has no dunning policy yet the two standard ones, so the proposal works from the first day: Swiss
 * (Art. 104 CO) and Italian (D.Lgs. 231/2002), valid for any currency. The figures are a starting point the company edits; the level
 * names and the texts of the notices are not here but in the seeded templates.
 */
final class DefaultPolicyProvisioner
{
    /**
     * Levels: level => [min days overdue, wait days, deadline days, fee, charge interest, legal warning, channels].
     *
     * @var array<string, array<int, array{0: int, 1: int, 2: int, 3: string, 4: bool, 5: bool, 6: list<string>}>>
     */
    private const array Levels = [
        'CH' => [
            0 => [1, 0, 7, '0.00', false, false, ['email', 'sms', 'whatsapp']],
            1 => [10, 10, 10, '0.00', false, false, ['email', 'registered_mail']],
            2 => [30, 10, 10, '20.00', true, false, ['email', 'registered_mail']],
            3 => [45, 10, 10, '30.00', true, true, ['registered_mail']],
        ],
        'IT' => [
            0 => [1, 0, 7, '0.00', false, false, ['email', 'sms', 'whatsapp']],
            1 => [10, 10, 10, '0.00', false, false, ['email', 'pec']],
            2 => [30, 10, 10, '20.00', true, false, ['email', 'pec']],
            3 => [45, 10, 10, '40.00', true, true, ['pec', 'registered_mail']],
        ],
    ];

    public function ensure(): void
    {
        if (Policy::query()->exists()) {
            return;
        }

        foreach (self::Levels as $jurisdiction => $levels) {
            $policy = Policy::create([
                'name' => $jurisdiction,
                'jurisdiction' => $jurisdiction,
                'currency' => null,
                'is_default' => true,
                'is_active' => true,
                'min_overdue_amount' => '0.00',
                'interest_basis' => InterestCalculator::Actual365,
            ]);

            foreach ($levels as $level => [$minDays, $wait, $deadline, $fee, $interest, $legal, $channels]) {
                $policy->levels()->create([
                    'level' => $level,
                    'min_days_overdue' => $minDays,
                    'wait_days' => $wait,
                    'deadline_days' => $deadline,
                    'fee_amount' => $fee,
                    'charge_interest' => $interest,
                    'channels' => $channels,
                    'legal_warning' => $legal,
                    'is_active' => true,
                ]);
            }
        }
    }
}
