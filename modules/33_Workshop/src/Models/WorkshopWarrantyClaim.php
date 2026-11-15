<?php

declare(strict_types=1);

namespace Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A warranty claim against the supplier of a part or service used on a work folder. The workshop claims an amount; the
 * supplier accepts or rejects it and, when accepted, reimburses an amount.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $claim_number
 * @property string $folder_id
 * @property ?string $folder_line_id
 * @property ?string $product_id
 * @property ?string $supplier_contact_id
 * @property string $status
 * @property string $currency
 * @property string $description
 * @property string $claimed_amount
 * @property ?string $reimbursed_amount
 */
final class WorkshopWarrantyClaim extends WorkshopRecord
{
    /**
     * Allowed transitions: from => targets.
     *
     * @var array<string, list<string>>
     */
    public const array Transitions = [
        'draft' => ['submitted'],
        'submitted' => ['accepted', 'rejected'],
        'accepted' => ['reimbursed'],
        'rejected' => [],
        'reimbursed' => [],
    ];

    protected $table = 'workshop_warranty_claims';

    protected function casts(): array
    {
        return [
            'claimed_amount' => 'decimal:2',
            'reimbursed_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'reimbursed_on' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<WorkshopFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(WorkshopFolder::class, 'folder_id');
    }
}
