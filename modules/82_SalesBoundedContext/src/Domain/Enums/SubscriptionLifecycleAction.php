<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\Enums;

enum SubscriptionLifecycleAction: string
{
    case Activate = 'activate';
    case Pause = 'pause';
    case Resume = 'resume';
    case Upgrade = 'upgrade';
    case Downgrade = 'downgrade';
    case Cancel = 'cancel';
    case RevalueIndex = 'revalue_index';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
