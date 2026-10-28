<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ChartOfAccount extends BaseEnterpriseModel
{
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
        });
    }

    protected function casts(): array
    {
        return [
            'is_reconciliation_account' => 'boolean',
            'allows_manual_posting' => 'boolean',
            'is_cost_center_mandatory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    public function subAccounts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }
}
