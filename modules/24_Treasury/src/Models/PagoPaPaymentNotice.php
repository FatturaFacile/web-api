<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Database\Factories\PagoPaPaymentNoticeFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Support\Carbon;

/**
 * A pagoPA payment notice ("avviso di pagamento") issued for a receivable: the debtor pays it at any PSP, bank, post
 * office or CBILL channel using the notice code or the QR code.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $invoice_id
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string $debtor_name
 * @property string|null $debtor_fiscal_code
 * @property string $amount
 * @property string $currency
 * @property Carbon $due_date
 * @property string $causale
 * @property string $iuv
 * @property string $notice_code
 * @property string $creditor_fiscal_code
 * @property string $status
 * @property Carbon|null $paid_at
 */
#[UseFactory(PagoPaPaymentNoticeFactory::class)]
final class PagoPaPaymentNotice extends BaseEnterpriseModel
{
    public const string Pending = 'pending';

    public const string Paid = 'paid';

    public const string Expired = 'expired';

    public const string Cancelled = 'cancelled';

    protected $table = 'pagopa_payment_notices';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }
}
