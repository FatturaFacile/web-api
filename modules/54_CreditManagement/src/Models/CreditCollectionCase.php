<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A collection case: the overdue invoices of one customer worked by a collector along a dunning strategy.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property string $case_number
 * @property string $status
 * @property string $priority
 * @property string|null $collector_id
 * @property string|null $strategy_id
 * @property int $current_step_order
 * @property string $outstanding_amount
 * @property string $currency
 * @property Carbon $opened_on
 * @property Carbon|null $next_action_on
 * @property Carbon|null $closed_on
 * @property string|null $notes
 */
final class CreditCollectionCase extends BaseEnterpriseModel
{
    public const string Open = 'open';

    public const string InDunning = 'in_dunning';

    public const string Promised = 'promised';

    public const string Disputed = 'disputed';

    public const string Escalated = 'escalated';

    public const string Resolved = 'resolved';

    public const string WrittenOff = 'written_off';

    /** @var list<string> */
    public const array Statuses = [self::Open, self::InDunning, self::Promised, self::Disputed, self::Escalated, self::Resolved, self::WrittenOff];

    /** @var list<string> */
    public const array ClosedStatuses = [self::Resolved, self::WrittenOff];

    /** @var list<string> */
    public const array Priorities = ['low', 'normal', 'high'];

    protected $table = 'credit_collection_cases';

    protected function casts(): array
    {
        return [
            'current_step_order' => 'integer',
            'outstanding_amount' => 'decimal:2',
            'opened_on' => 'date',
            'next_action_on' => 'date',
            'closed_on' => 'date',
        ];
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::ClosedStatuses, true);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return BelongsTo<CreditDunningStrategy, $this>
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(CreditDunningStrategy::class, 'strategy_id');
    }

    /**
     * @return HasMany<CreditCaseInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(CreditCaseInvoice::class, 'case_id');
    }

    /**
     * @return HasMany<CreditCollectionAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(CreditCollectionAction::class, 'case_id')->orderBy('performed_on')->orderBy('created_at');
    }

    /**
     * @return HasMany<CreditPromiseToPay, $this>
     */
    public function promises(): HasMany
    {
        return $this->hasMany(CreditPromiseToPay::class, 'case_id')->orderBy('promised_on');
    }

    /**
     * @return HasMany<CreditDispute, $this>
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(CreditDispute::class, 'case_id');
    }
}
