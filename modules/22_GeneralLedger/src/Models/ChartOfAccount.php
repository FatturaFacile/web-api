<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Multi-standard Chart of Accounts node supporting unlimited parent-child hierarchy,
 * statutory classifications, accounting properties (default VAT, default cost center,
 * open items, allowed currencies), and bidirectional cross-standard mappings.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $account_code
 * @property string $name
 * @property string|null $description
 * @property string|null $account_class
 * @property string $account_type
 * @property string $normal_balance
 * @property string|null $parent_account_id
 * @property int $level
 * @property string|null $hierarchy_path
 * @property string|null $currency
 * @property string|null $vat_code_id
 * @property string|null $default_cost_center_id
 * @property bool $open_items_enabled
 * @property list<string>|null $allowed_currencies
 * @property bool $is_postable
 * @property bool $is_reconciliation_account
 * @property string|null $reconciliation_type
 * @property bool $allows_manual_posting
 * @property bool $is_cost_center_mandatory
 * @property bool $is_system
 * @property bool $is_active
 * @property string|null $template_code
 * @property string|null $statutory_code
 * @property string|null $reporting_class
 * @property-read ChartOfAccount|null $parentAccount
 * @property-read VatCode|null $vatCode
 * @property-read CostCenter|null $defaultCostCenter
 */
final class ChartOfAccount extends BaseEnterpriseModel
{
    use BelongsToCompany;

    protected $table = 'chart_of_accounts';

    protected static function booted(): void
    {
        parent::booted();

        self::saving(function (self $model): void {
            if (empty($model->code) && ! empty($model->account_code)) {
                $model->code = $model->account_code;
            }
            if (empty($model->account_code) && ! empty($model->code)) {
                $model->account_code = $model->code;
            }
            if (empty($model->standard)) {
                $model->standard = 'it_general_ledger';
            }
            if (empty($model->type)) {
                $typeMap = [
                    'asset' => 'asset',
                    'liability' => 'liability',
                    'equity' => 'equity',
                    'revenue' => 'revenue',
                    'expense' => 'expense',
                ];
                $model->type = $typeMap[$model->account_class ?? ''] ?? $typeMap[$model->account_type ?? ''] ?? 'asset';
            }

            // Keep is_postable and allows_manual_posting synchronized
            if ($model->isDirty('is_postable') && ! $model->isDirty('allows_manual_posting')) {
                $model->allows_manual_posting = (bool) $model->is_postable;
            } elseif ($model->isDirty('allows_manual_posting') && ! $model->isDirty('is_postable')) {
                $model->is_postable = (bool) $model->allows_manual_posting;
            } elseif (! $model->exists && $model->getAttribute('is_postable') === null) {
                $model->is_postable = (bool) ($model->getAttribute('allows_manual_posting') ?? true);
            }

            // Compute hierarchy level and slash-delimited path
            if (! empty($model->parent_account_id)) {
                $parent = self::withoutGlobalScopes()->whereKey($model->parent_account_id)->first(['id', 'level', 'hierarchy_path', 'account_code']);
                if ($parent !== null) {
                    $model->level = ((int) ($parent->level ?? 1)) + 1;
                    $parentPath = $parent->hierarchy_path ?: (string) $parent->account_code;
                    $model->hierarchy_path = $parentPath.'/'.$model->account_code;
                }
            } else {
                $model->level = 1;
                $model->hierarchy_path = (string) $model->account_code;
            }
        });
    }

    /**
     * Calculated grid columns as portable SQL, so they filter, sort, group and list their values in the database.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            // Booleans are cast to different texts by SQLite and PostgreSQL: a text state is the same on both.
            'activity_state' => "CASE WHEN chart_of_accounts.is_active THEN 'active' ELSE 'inactive' END",
        ];
    }

    protected function casts(): array
    {
        return [
            'is_reconciliation_account' => 'boolean',
            'allows_manual_posting' => 'boolean',
            'is_postable' => 'boolean',
            'open_items_enabled' => 'boolean',
            'is_cost_center_mandatory' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'level' => 'integer',
            'allowed_currencies' => 'array',
        ];
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    public function subAccounts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id')->orderBy('account_code');
    }

    public function childrenRecursive(): HasMany
    {
        return $this->subAccounts()->with(['childrenRecursive', 'vatCode', 'defaultCostCenter']);
    }

    public function vatCode(): BelongsTo
    {
        return $this->belongsTo(VatCode::class, 'vat_code_id');
    }

    public function defaultCostCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'default_cost_center_id');
    }

    /**
     * @return HasMany<AccountMapping, $this>
     */
    public function outgoingMappings(): HasMany
    {
        return $this->hasMany(AccountMapping::class, 'source_account_id');
    }

    /**
     * @return HasMany<AccountMapping, $this>
     */
    public function incomingMappings(): HasMany
    {
        return $this->hasMany(AccountMapping::class, 'target_account_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    /**
     * Recompute hierarchy_path and level for this account and all its descendants.
     */
    public function syncSubtreeHierarchy(): void
    {
        $children = self::withoutGlobalScopes()
            ->where('parent_account_id', $this->id)
            ->get();

        foreach ($children as $child) {
            $child->level = ((int) $this->level) + 1;
            $child->hierarchy_path = ($this->hierarchy_path ?: (string) $this->account_code).'/'.$child->account_code;
            $child->saveQuietly();
            $child->syncSubtreeHierarchy();
        }
    }
}
