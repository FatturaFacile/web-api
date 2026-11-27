<?php

declare(strict_types=1);

namespace Modules\Esterometro\Domain\Enums;

enum TransactionNature: string
{
    case DomesticRc = 'DOMESTIC_RC';
    case Services = 'SERVICES';
    case Goods = 'GOODS';
    case GoodsInItaly = 'GOODS_IN_ITALY';
    case Regularization = 'REGULARIZATION';
    case SanMarino = 'SAN_MARINO';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
