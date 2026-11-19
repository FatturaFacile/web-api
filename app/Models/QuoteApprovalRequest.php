<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The decision one approval level has to take on a quote, with its audit trail.
 */
class QuoteApprovalRequest extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const string Pending = 'pending';

    public const string Approved = 'approved';

    public const string Rejected = 'rejected';

    protected $fillable = [
        'tenant_id',
        'quote_id',
        'level',
        'required_role',
        'status',
        'triggers',
        'requested_by',
        'approved_by',
        'rejected_by',
        'decided_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'triggers' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
