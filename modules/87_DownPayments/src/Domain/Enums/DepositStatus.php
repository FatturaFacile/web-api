<?php

declare(strict_types=1);

namespace Modules\DownPayments\Domain\Enums;

/** Lifecycle of a deposit. */
enum DepositStatus: string
{
    case IssuedUnpaid = 'issued_unpaid';
    case HeldActive = 'held_active';
    case ConvertedToAdvance = 'converted_to_advance';
    case DeductedInFinalInvoice = 'deducted_in_final_invoice';
    case ForfeitedOnBreach = 'forfeited_on_breach';
    case RefundedSingle = 'refunded_single';
    case RefundedDoubleArt1385 = 'refunded_double_art1385';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
