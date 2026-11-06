<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Monthly register of what was withheld on the supplier invoices paid in the month.
 */
final class WithholdingSettlement extends Model
{
    use BelongsToTenant, HasUuids;

    public const string STATUS_CALCULATED = 'calculated';

    public const string STATUS_PAID = 'paid';

    protected $fillable = [
        'tenant_id', 'year', 'month', 'currency', 'total_withheld', 'total_f24', 'due_date', 'status', 'payment_date',
        'payment_journal_id', 'calculated_by', 'paid_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'total_withheld' => 'decimal:2',
            'total_f24' => 'decimal:2',
            'due_date' => 'date',
            'payment_date' => 'date',
        ];
    }

    /**
     * @return HasMany<WithholdingSettlementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(WithholdingSettlementLine::class)->orderBy('paid_at')->orderBy('invoice_number');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function periodLabel(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }
}
