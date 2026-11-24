<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Models;

use App\Models\User;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The general ledger entry and the bank statement line of a client funds ledger row. Kept apart from the ledger row,
 * which is append-only.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $trust_transaction_id
 * @property ?string $journal_entry_id
 * @property ?string $bank_statement_line_id
 */
final class PracticeTrustPosting extends Model
{
    use BelongsToTenant, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'practice_trust_postings';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['posted_at' => 'datetime', 'reconciled_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<PracticeTrustTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PracticeTrustTransaction::class, 'trust_transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
