<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The counting policy of a tenant: ABC shares and look-back window, the cadence of every class, when a count is
 * recounted, who approves which variance (two levels, each by percentage and/or value) and the ledger accounts of the
 * variances. A tenant without a stored row works with DEFAULTS: no automatic recount, the legacy single approval
 * threshold (`approval_mode = workflow`) and the conventional 80/95 Pareto split.
 *
 * @property string $approval_mode `workflow` (legacy single threshold, INVENTORY_WRITE_OFF_APPROVAL) or `multi_level`
 * @property string $abc_a_share
 * @property string $abc_b_share
 * @property int $abc_period_days
 * @property int $interval_days_a
 * @property int $interval_days_b
 * @property int $interval_days_c
 * @property string $new_item_class
 * @property bool $blind_by_default
 * @property bool $reveal_first_count_on_recount
 * @property string|null $recount_threshold_percent
 * @property string|null $recount_threshold_value
 * @property int $max_recount_rounds
 * @property bool $auto_approve_within_tolerance
 * @property string|null $level1_threshold_percent
 * @property string|null $level1_threshold_value
 * @property string|null $level2_threshold_percent
 * @property string|null $level2_threshold_value
 * @property bool $enforce_segregation_of_duties
 * @property string $inventory_account_code
 * @property string $gain_account_code
 * @property string $loss_account_code
 */
final class InventoryCountPolicy extends BaseEnterpriseModel
{
    public const string MODE_WORKFLOW = 'workflow';

    public const string MODE_MULTI_LEVEL = 'multi_level';

    /** @var array<string, mixed> */
    public const array DEFAULTS = [
        'abc_a_share' => '80.00',
        'abc_b_share' => '95.00',
        'abc_period_days' => 365,
        'interval_days_a' => 30,
        'interval_days_b' => 90,
        'interval_days_c' => 180,
        'new_item_class' => 'C',
        'blind_by_default' => true,
        'reveal_first_count_on_recount' => false,
        'recount_threshold_percent' => null,
        'recount_threshold_value' => null,
        'max_recount_rounds' => 2,
        'approval_mode' => self::MODE_WORKFLOW,
        'auto_approve_within_tolerance' => true,
        'level1_threshold_percent' => null,
        'level1_threshold_value' => null,
        'level2_threshold_percent' => null,
        'level2_threshold_value' => null,
        'enforce_segregation_of_duties' => true,
        'inventory_account_code' => '1200',
        'gain_account_code' => '4910',
        'loss_account_code' => '5100',
    ];

    protected $table = 'inventory_count_policies';

    /**
     * The stored policy of the tenant, or an unsaved one holding the defaults.
     */
    public static function forTenant(?string $tenantId): self
    {
        $stored = $tenantId === null ? null : self::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        if ($stored !== null) {
            return $stored;
        }

        return (new self)->forceFill(self::DEFAULTS + ['tenant_id' => $tenantId]);
    }

    /**
     * monthly / quarterly / semi_annual / annual for the conventional intervals, `custom` otherwise.
     */
    public static function cadenceLabel(int $days): string
    {
        return match ($days) {
            30 => 'monthly',
            90 => 'quarterly',
            180 => 'semi_annual',
            365 => 'annual',
            default => 'custom',
        };
    }

    /**
     * The interval of a class' cycle counts in days.
     */
    public function intervalDaysFor(string $abcClass): int
    {
        return match ($abcClass) {
            'A' => $this->interval_days_a,
            'B' => $this->interval_days_b,
            default => $this->interval_days_c,
        };
    }

    public function isMultiLevel(): bool
    {
        return $this->approval_mode === self::MODE_MULTI_LEVEL;
    }

    protected function casts(): array
    {
        return [
            'abc_a_share' => 'decimal:2',
            'abc_b_share' => 'decimal:2',
            'abc_period_days' => 'integer',
            'interval_days_a' => 'integer',
            'interval_days_b' => 'integer',
            'interval_days_c' => 'integer',
            'blind_by_default' => 'boolean',
            'reveal_first_count_on_recount' => 'boolean',
            'recount_threshold_percent' => 'decimal:2',
            'recount_threshold_value' => 'decimal:2',
            'max_recount_rounds' => 'integer',
            'auto_approve_within_tolerance' => 'boolean',
            'level1_threshold_percent' => 'decimal:2',
            'level1_threshold_value' => 'decimal:2',
            'level2_threshold_percent' => 'decimal:2',
            'level2_threshold_value' => 'decimal:2',
            'enforce_segregation_of_duties' => 'boolean',
        ];
    }
}
