<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Carbon\CarbonImmutable;
use LogicException;

/**
 * A withholding tax statement as it was closed: what was declared, kept so that later changes become corrections. The
 * content holds identification numbers and is encrypted. A closed statement never changes.
 *
 * @property array{lines: list<array<string, mixed>>, corrections: list<array<string, mixed>>} $content
 * @property CarbonImmutable $closed_at
 */
final class PayrollWithholdingStatement extends PayrollRecord
{
    public const string Monthly = 'monthly';

    public const string Quarterly = 'quarterly';

    public const string Annual = 'annual';

    /** @var list<string> */
    public const array PeriodTypes = [self::Monthly, self::Quarterly, self::Annual];

    protected $table = 'payroll_withholding_statements';

    protected $hidden = ['content'];

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException('A closed withholding tax statement cannot be changed.');
        });
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'period' => 'integer',
            'content' => 'encrypted:array',
            'total_tax' => 'decimal:2',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
