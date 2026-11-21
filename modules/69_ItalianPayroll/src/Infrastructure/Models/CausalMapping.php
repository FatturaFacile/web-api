<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A tenant override of the consultant voice code of an internal causal.
 *
 * @property string $consultant_format
 * @property string $causal_code
 * @property string $external_code
 */
final class CausalMapping extends BaseEnterpriseModel
{
    protected $table = 'ipp_causal_mappings';
}
