<?php

declare(strict_types=1);

namespace Modules\DownPayments\Domain\Enums;

/** The event that makes an installment of the payment plan due. */
enum InstallmentTrigger: string
{
    case OnOrderConfirmation = 'on_order_confirmation';
    case OnMaterialDelivery = 'on_material_delivery';
    case OnMilestoneSal = 'on_milestone_sal';
    case OnCommissioning = 'on_commissioning';
    case CustomDate = 'custom_date';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
