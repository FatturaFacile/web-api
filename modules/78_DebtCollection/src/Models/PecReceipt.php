<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * An acceptance or delivery receipt of a certified e-mail notice.
 *
 * @property string $notice_id
 * @property string $kind
 * @property string|null $message_id
 * @property Carbon $received_at
 * @property string|null $notes
 * @property string|null $attachment_path
 */
final class PecReceipt extends BaseEnterpriseModel
{
    protected $table = 'dc_pec_receipts';

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
