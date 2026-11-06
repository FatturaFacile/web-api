<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RibaPresentation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'payment_batch_id', 'sia_code', 'file_name', 'flat_file', 'file_sha256', 'exported_at'];

    /**
     * @var list<string>
     */
    protected $hidden = ['flat_file'];

    protected function casts(): array
    {
        return ['exported_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<PaymentBatch, $this>
     */
    public function paymentBatch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }

    /**
     * @return HasMany<RibaReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(RibaReceipt::class);
    }
}
