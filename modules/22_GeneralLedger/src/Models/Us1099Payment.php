<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment made to a 1099 recipient, with the form and box it is reported in.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $recipient_id
 * @property Carbon $paid_on
 * @property string $amount
 * @property string $currency
 * @property string $form_type
 * @property string $box
 * @property string|null $payment_method
 * @property string|null $reference
 */
final class Us1099Payment extends BaseEnterpriseModel
{
    /**
     * Reportable boxes by form.
     *
     * @var array<string, list<string>>
     */
    public const array Boxes = [
        '1099-NEC' => ['1', '4'],
        '1099-MISC' => ['1', '2', '3', '6', '10'],
    ];

    /** Annual reporting threshold in US dollars (2026 onwards: 2,000). */
    public const string Threshold = '2000.00';

    protected $table = 'us_1099_payments';

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Us1099Recipient, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Us1099Recipient::class, 'recipient_id');
    }
}
