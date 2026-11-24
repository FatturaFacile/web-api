<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Alert extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    /**
     * Entities an alert can be attached to, keyed by their API alias.
     *
     * @var array<string, class-string<Model>>
     */
    public const TARGET_TYPES = [
        'contact' => Contact::class,
        'invoice' => Invoice::class,
        'quote' => Quote::class,
        'sales_order' => SalesOrder::class,
        'delivery_note' => DeliveryNote::class,
    ];

    protected $fillable = [
        'tenant_id',
        'alertable_type',
        'alertable_id',
        'created_by',
        'title',
        'message',
        'severity',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function alertable(): MorphTo
    {
        return $this->morphTo();
    }
}
