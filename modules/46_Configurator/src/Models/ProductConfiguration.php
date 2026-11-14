<?php

declare(strict_types=1);

namespace Modules\Configurator\Models;

use App\Models\Contact;
use App\Models\Quote;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved configuration of a model: the selections, the price and margin of the last evaluation and the approval that
 * the margin guard may require. Once converted, it keeps the snapshot of the model version, the rules and the prices
 * that produced the quote and is closed to changes.
 *
 * draft -> validated -> (pending_approval -> approved | rejected) -> converted. Changing the selections of a validated,
 * approved or rejected configuration puts it back to draft.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $model_id
 * @property string $number
 * @property string|null $name
 * @property string|null $contact_id
 * @property string|null $price_list_id
 * @property string $quantity
 * @property string $discount_percent
 * @property string $currency
 * @property list<array{group: string, options?: list<string>, value?: string|null}>|null $selections
 * @property array<string, mixed>|null $pricing
 * @property string $total_net
 * @property string $total_cost
 * @property string|null $margin_percent
 * @property string|null $min_margin_percent
 * @property bool $requires_approval
 * @property string $status
 * @property int|null $model_version
 * @property string|null $created_by
 * @property Carbon|null $validated_at
 * @property string|null $approval_requested_by
 * @property Carbon|null $approval_requested_at
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_notes
 * @property string|null $quote_id
 * @property Carbon|null $converted_at
 * @property array<string, mixed>|null $snapshot
 */
final class ProductConfiguration extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Validated = 'validated';

    public const string PendingApproval = 'pending_approval';

    public const string Approved = 'approved';

    public const string Rejected = 'rejected';

    public const string Converted = 'converted';

    /** @var list<string> */
    public const array Statuses = [self::Draft, self::Validated, self::PendingApproval, self::Approved, self::Rejected, self::Converted];

    /** @var array<string, list<string>> */
    public const array Transitions = [
        self::Draft => [self::Validated],
        self::Validated => [self::Draft, self::PendingApproval, self::Converted],
        self::PendingApproval => [self::Approved, self::Rejected],
        self::Approved => [self::Draft, self::Converted],
        self::Rejected => [self::Draft],
        self::Converted => [],
    ];

    protected $table = 'cfg_configurations';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'selections' => 'array',
            'pricing' => 'array',
            'total_net' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'margin_percent' => 'decimal:2',
            'min_margin_percent' => 'decimal:2',
            'requires_approval' => 'boolean',
            'model_version' => 'integer',
            'validated_at' => 'datetime',
            'approval_requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'converted_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ConfigurableModel, $this>
     */
    public function configurableModel(): BelongsTo
    {
        return $this->belongsTo(ConfigurableModel::class, 'model_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::Transitions[$this->status] ?? [], true);
    }

    public function isClosed(): bool
    {
        return $this->status === self::Converted;
    }
}
