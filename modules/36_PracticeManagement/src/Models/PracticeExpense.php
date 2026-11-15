<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Models;

use App\Models\LegalCase;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An out-of-pocket or third-party expense incurred on a matter; billable expenses are recovered on the fee note.
 *
 * @property string $id
 * @property ?string $tenant_id
 */
final class PracticeExpense extends Model
{
    use BelongsToTenant, HasUuidV7;

    /** @var list<string> */
    public const array Categories = ['court_fee', 'travel', 'filing', 'expert', 'courier', 'other'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'practice_expenses';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /** @var list<string> */
    protected $hidden = ['receipt_disk', 'receipt_path'];

    /** @var list<string> */
    protected $appends = ['has_receipt'];

    /**
     * Whether a receipt file is attached to the expense.
     *
     * @return Attribute<bool, never>
     */
    protected function hasReceipt(): Attribute
    {
        return Attribute::get(fn (): bool => $this->receipt_path !== null);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expense_date' => 'immutable_date', 'amount' => 'decimal:2', 'is_billable' => 'boolean', 'is_billed' => 'boolean'];
    }

    /**
     * @return BelongsTo<LegalCase, $this>
     */
    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'legal_case_id');
    }
}
