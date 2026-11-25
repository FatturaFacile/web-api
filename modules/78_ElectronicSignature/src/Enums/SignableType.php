<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Enums;

/**
 * The documents of the platform that can be sent for signature. `upload` is a standalone PDF with no business record.
 */
enum SignableType: string
{
    case Quote = 'quote';
    case PurchaseOrder = 'purchase_order';
    case EmploymentContract = 'employment_contract';
    case Document = 'document';
    case Upload = 'upload';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
