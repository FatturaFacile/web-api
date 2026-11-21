<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Receipt of a processed MES command: a retry with the same idempotency key finds it and is answered without running
 * the command twice.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $idempotency_key
 * @property string $command
 * @property string|null $phase_id
 */
final class MfgMesCommandReceipt extends BaseEnterpriseModel
{
    protected $table = 'mfg_mes_command_receipts';
}
