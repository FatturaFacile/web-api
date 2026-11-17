<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\GeneralLedger\Exceptions\VatSettlementException;

/**
 * A VAT period of the CH/IT settlement engine. Once CLOSED or FILED the frozen calculation never changes.
 *
 * @property array<string, mixed> $calculation
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property CarbonImmutable|null $locked_at
 * @property CarbonImmutable|null $filed_at
 */
final class VatSettlementPeriod extends BaseEnterpriseModel
{
    public const string STATUS_DRAFT = 'DRAFT';

    public const string STATUS_CLOSED = 'CLOSED';

    public const string STATUS_FILED = 'FILED';

    protected $table = 'vat_settlement_periods';

    protected static function booted(): void
    {
        parent::booted();

        self::updating(static function (self $period): void {
            $original = (string) $period->getOriginal('status');

            if ($original === self::STATUS_DRAFT) {
                return;
            }

            $onlyFiling = $original === self::STATUS_CLOSED
                && $period->status === self::STATUS_FILED
                && array_diff(array_keys($period->getDirty()), ['status', 'filed_at', 'updated_at']) === [];

            if (! $onlyFiling) {
                throw VatSettlementException::conflict('A locked VAT period is immutable.');
            }
        });

        self::deleting(static function (self $period): void {
            if ($period->status !== self::STATUS_DRAFT) {
                throw VatSettlementException::conflict('A locked VAT period is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'calculation' => 'array',
            'payable_amount' => 'decimal:2',
            'locked_at' => 'immutable_datetime',
            'filed_at' => 'immutable_datetime',
        ];
    }
}
