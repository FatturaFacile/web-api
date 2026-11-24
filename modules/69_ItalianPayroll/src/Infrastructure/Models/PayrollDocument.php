<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * An encrypted payslip or CU file kept on a private disk; only the path and the SHA-256 of the clear PDF are stored here.
 *
 * @property string $id
 * @property string $kind cedolino|cu
 * @property string $disk
 * @property string $path
 * @property string $sha256
 * @property int $size_bytes
 */
final class PayrollDocument extends BaseEnterpriseModel
{
    protected $table = 'ipp_documents';
}
