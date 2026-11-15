<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Models;

use App\Models\Contact;
use App\Models\LegalCase;
use App\Support\Database\Concerns\ProtectsRecords;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the append-only client funds (trust) ledger. Deposits add to the balance of the client (optionally of one
 * matter); withdrawals and fee transfers take from it and never take it below zero. A row is never changed or deleted:
 * a mistake is corrected with a reversal row.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $client_id
 * @property ?string $legal_case_id
 * @property string $type
 * @property string $amount
 * @property string $signed_amount
 * @property string $balance_after
 * @property ?string $reverses_id
 */
final class PracticeTrustTransaction extends Model
{
    use BelongsToTenant, HasUuidV7, ProtectsRecords;

    /** @var list<string> */
    public const array Types = ['deposit', 'withdrawal', 'fee_transfer', 'reversal'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'practice_trust_transactions';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['occurred_on' => 'immutable_date', 'amount' => 'decimal:2', 'signed_amount' => 'decimal:2', 'balance_after' => 'decimal:2'];
    }

    protected function recordProtectionViolation(string $operation): ?string
    {
        return $operation === 'create' ? null : 'The client funds ledger is append-only: post a reversal.';
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'client_id');
    }

    /**
     * @return BelongsTo<LegalCase, $this>
     */
    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'legal_case_id');
    }
}
