<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use App\Models\PassiveInvoice;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An import customs bill (bolletta doganale / Einfuhrabgabenbescheid): non-taxable duties kept apart from the import VAT.
 */
/**
 * @property Carbon $bill_date
 */
final class CustomsImportBill extends CustomsRecord
{
    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_POSTED = 'posted';

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'customs_value' => 'decimal:2',
            'duty_amount' => 'decimal:2',
            'ancillary_costs' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'import_vat_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CustomsDeclaration, $this>
     */
    public function declaration(): BelongsTo
    {
        return $this->belongsTo(CustomsDeclaration::class, 'declaration_id');
    }

    /**
     * The invoice of the customs broker that charged the duties and the VAT on.
     *
     * @return BelongsTo<PassiveInvoice, $this>
     */
    public function passiveInvoice(): BelongsTo
    {
        return $this->belongsTo(PassiveInvoice::class, 'passive_invoice_id');
    }
}
