<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DocumentEmail extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    /**
     * Documents that can be emailed, keyed by their API alias.
     *
     * @var array<string, class-string<Model>>
     */
    public const TARGET_TYPES = [
        'invoice' => Invoice::class,
        'quote' => Quote::class,
        'sales_order' => SalesOrder::class,
        'delivery_note' => DeliveryNote::class,
        'work_order' => WorkOrder::class,
        'service_report' => ServiceReport::class,
    ];

    protected $fillable = [
        'tenant_id',
        'emailable_type',
        'emailable_id',
        'user_id',
        'recipient',
        'subject',
        'message',
        'status',
        'error',
        'sent_at',
        'attachment_name',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function emailable(): MorphTo
    {
        return $this->morphTo();
    }
}
