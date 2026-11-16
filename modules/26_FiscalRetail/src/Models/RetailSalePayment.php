<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Models;

use Database\Factories\RetailSalePaymentFactory;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One payment of a POS sale: the sale amount is split across as many methods as the customer uses.
 */
#[UseFactory(RetailSalePaymentFactory::class)]
final class RetailSalePayment extends Model
{
    /** @use HasFactory<RetailSalePaymentFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'retail_sale_payments';

    protected $fillable = ['tenant_id', 'pos_sale_id', 'method', 'amount', 'reference'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** Methods accepted at the till; return_credit is the value of goods given back in an exchange. */
    public const array Methods = ['cash', 'card', 'voucher', 'bank_transfer', 'mobile', 'other'];

    public const string ReturnCredit = 'return_credit';
}
