<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Legacy view of the chart of accounts. The table is shared with Modules\GeneralLedger\Models\ChartOfAccount, whose
 * ledger reads `account_code`, `account_type` and `normal_balance`; this model writes `code` and `type`, so it fills
 * the module columns on save, otherwise an account created here would land in the trial balance with no code and as
 * a debit asset.
 */
class ChartOfAccount extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'type',
        'standard',
        'is_active',
    ];

    /**
     * Normal balance of the legacy account types, the convention the chart of accounts seeder uses as well.
     *
     * @var array<string, string>
     */
    public const array NORMAL_BALANCE = ['asset' => 'debit', 'expense' => 'debit', 'liability' => 'credit', 'equity' => 'credit', 'revenue' => 'credit'];

    /**
     * Mirrors `code` / `type` into the columns of the General Ledger module when they are not set explicitly.
     */
    protected static function booted(): void
    {
        static::saving(static function (self $account): void {
            if (empty($account->getAttribute('account_code')) && ! empty($account->getAttribute('code'))) {
                $account->setAttribute('account_code', $account->getAttribute('code'));
            }

            $type = (string) $account->getAttribute('type');

            if (! isset(self::NORMAL_BALANCE[$type]) || ($account->exists && ! $account->isDirty('type'))) {
                return;
            }

            $mirrored = ['account_class' => $type, 'account_type' => $type, 'normal_balance' => self::NORMAL_BALANCE[$type]];

            foreach ($mirrored as $column => $value) {
                $keepsInSync = $account->exists && $account->getOriginal('account_type') === $account->getOriginal('type');

                if ($keepsInSync || (! $account->exists && empty($account->getAttribute($column)))) {
                    $account->setAttribute($column, $value);
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Lines of the legacy ledger tables, which are no longer written: the ledger is `journal_lines`.
     *
     * @deprecated Read Modules\GeneralLedger\Models\ChartOfAccount::journalLines().
     *
     * @return HasMany<GeneralLedgerLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GeneralLedgerLine::class, 'account_id');
    }
}
