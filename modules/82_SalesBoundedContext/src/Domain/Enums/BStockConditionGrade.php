<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\Enums;

enum BStockConditionGrade: string
{
    case GradeALikeNew = 'grade_a_like_new';
    case GradeBRefurbished = 'grade_b_refurbished';
    case GradeCSalvage = 'grade_c_salvage';
    case ScrapWriteoff = 'scrap_writeoff';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
