<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    /**
     * Records that can carry attachments, keyed by their API alias.
     *
     * @var array<string, class-string<Model>>
     */
    public const TARGET_TYPES = [
        'contact' => Contact::class,
        'invoice' => Invoice::class,
        'quote' => Quote::class,
        'sales_order' => SalesOrder::class,
        'delivery_note' => DeliveryNote::class,
        'work_order' => WorkOrder::class,
        'purchase_order' => PurchaseOrder::class,
        'supplier_invoice' => PassiveInvoice::class,
        'service_report' => ServiceReport::class,
        'ticket' => Ticket::class,
        'appointment' => Appointment::class,
        'expense' => Expense::class,
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['path'];

    protected $fillable = [
        'tenant_id',
        'attachable_type',
        'attachable_id',
        'uploaded_by',
        'original_name',
        'disk',
        'path',
        'mime_type',
        'size',
    ];

    /**
     * Resolve the storage disk for this attachment, defaulting to the application private disk.
     */
    public function getStorageDisk(): string
    {
        return $this->disk ?? (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
