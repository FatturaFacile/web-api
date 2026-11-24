<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Models;

use Database\Factories\RetailReturnFactory;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A return of goods of a POS sale, with an optional exchange sale, settled in an open cash session.
 */
#[UseFactory(RetailReturnFactory::class)]
final class RetailReturn extends Model
{
    /** @use HasFactory<RetailReturnFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'retail_returns';

    protected $fillable = ['tenant_id', 'pos_sale_id', 'pos_session_id', 'exchange_sale_id', 'commercial_document_id', 'number', 'reason', 'refund_method', 'total_net', 'total_tax', 'total_gross', 'credit_applied', 'cash_refunded', 'other_refunded', 'user_id'];

    protected function casts(): array
    {
        return ['total_net' => 'decimal:2', 'total_tax' => 'decimal:2', 'total_gross' => 'decimal:2', 'credit_applied' => 'decimal:2', 'cash_refunded' => 'decimal:2', 'other_refunded' => 'decimal:2'];
    }

    public const array RefundMethods = ['cash', 'card', 'other'];

    /**
     * @return HasMany<RetailReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RetailReturnLine::class, 'retail_return_id');
    }
}
