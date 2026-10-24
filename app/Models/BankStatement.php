<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An imported bank statement (CBI, CAMT.053) or notification (CAMT.054) with its reconciliation progress.
 */
class BankStatement extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const DRAFT = 'draft';

    public const PARTIALLY_RECONCILED = 'partially_reconciled';

    public const RECONCILED = 'reconciled';

    protected $fillable = [
        'tenant_id',
        'source_format',
        'account_iban',
        'statement_date',
        'opening_balance',
        'closing_balance',
        'currency',
        'total_entries',
        'status',
        'content_hash',
    ];

    protected $hidden = ['content_hash'];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'total_entries' => 'integer',
        ];
    }

    /**
     * @return HasMany<BankStatementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('line_number')->orderBy('id');
    }
}
