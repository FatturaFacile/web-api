<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\Hse\Domain\Enums\PpeDeliveryStatus;

/**
 * A PPE (DPI / PSA) hand-over with the cryptographic hash of the worker signature.
 *
 * @property string $employee_id
 * @property string $product_id
 * @property string $warehouse_id
 * @property string|null $lot_or_serial_id
 * @property numeric-string $quantity
 * @property string|null $ce_marking_standard
 * @property Carbon $handed_over_at
 * @property Carbon|null $replacement_due_at
 * @property string $employee_signature_hash
 * @property string|null $signature_image_base64_or_ref
 * @property string|null $verbale_consegna_pdf_id
 * @property string|null $stock_movement_id
 * @property string $locale
 * @property PpeDeliveryStatus $delivery_status
 */
final class PpeDistributionRecord extends BaseEnterpriseModel
{
    protected $table = 'hse_ppe_distributions';

    /** @var list<string> */
    protected $hidden = ['signature_image_base64_or_ref'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'handed_over_at' => 'datetime',
            'replacement_due_at' => 'date',
            'delivery_status' => PpeDeliveryStatus::class,
        ];
    }

    /**
     * The signature image is biometric data: the audit ledger keeps the hash only.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        unset($attributes['signature_image_base64_or_ref']);

        return $attributes;
    }
}
