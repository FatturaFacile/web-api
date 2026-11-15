<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The documents whose creator cannot be the person who approves or releases them, when the company configures the
 * per-document segregation of duties rule (governance module).
 */
enum SegregatedDocument: string
{
    case Payment = 'payment';
    case PurchaseOrder = 'purchase_order';
    case Journal = 'journal';
    case PayrollRun = 'payroll_run';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $document): string => $document->value, self::cases());
    }
}
