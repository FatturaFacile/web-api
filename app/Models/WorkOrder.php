<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'ticket_id',
        'client_id',
        'technician_user_id',
        'work_order_number',
        'date',
        'labor_hours',
        'hourly_rate',
        'parts_total',
        'total_amount',
        'client_signature_path',
        'client_signed_by',
        'signed_at',
        'status',
        'notes',
        'started_at',
        'ended_at',
        'intervention_address',
        'invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'ended_at' => 'datetime',
            'hourly_rate' => 'decimal:2',
            'labor_hours' => 'decimal:2',
            'parts_total' => 'decimal:2',
            'signed_at' => 'datetime',
            'started_at' => 'datetime',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<WorkOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(WorkOrderLine::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Alias so generic document services can read the customer through `contact_id`.
     */
    protected function contactId(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->client_id);
    }
}
