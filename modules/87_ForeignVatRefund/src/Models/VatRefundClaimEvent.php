<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Immutable trail of a claim: creation, status changes, generated exports, reconciliation.
 *
 * @property string $vat_refund_claim_id
 * @property string $event
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $note
 * @property array<string, mixed>|null $payload
 * @property string|null $user_id
 */
final class VatRefundClaimEvent extends BaseEnterpriseModel
{
    protected $table = 'vat_refund_claim_events';

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
