<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\HasImmutableAuditTrail;
use App\Domain\Audit\Contracts\AuditableEntityInterface;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model implements AuditableEntityInterface
{
    use BelongsToTenant, HasFactory, HasUuids;
    use HasImmutableAuditTrail;

    protected $fillable = [
        'invoice_id',
        'amount',
        'paid_at',
        'payment_method',
        'reference',
        'notes',
        'type',
        'bank_statement_line_id',
        'bank_account_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
