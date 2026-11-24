<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Part of a credit note applied to an invoice of the same customer. The applied amount is already counted in the paid
 * total of the invoice, so an application is never deleted: a correction is a new movement. The table therefore has no
 * `deleted_at`, and the soft deletes every enterprise model inherits are switched off here.
 */
final class InvoiceCreditApplication extends BaseEnterpriseModel
{
    protected $table = 'invoice_credit_applications';

    /**
     * No soft-delete scope: the table has no `deleted_at` column and every application counts.
     */
    public static function bootSoftDeletes(): void {}

    /**
     * No `deleted_at` cast, for the same reason.
     */
    public function initializeSoftDeletes(): void {}

    protected function casts(): array
    {
        return [
            'applied_amount' => 'decimal:2',
            'application_date' => 'date',
        ];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'credit_note_id');
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'original_invoice_id');
    }

    /**
     * @throws LogicException always: deleting an application would leave the paid total of the invoice wrong
     */
    protected function performDeleteOnModel(): void
    {
        throw new LogicException('A credit note application is part of the paid total of the invoice and cannot be deleted.');
    }
}
