<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * @property string $role
 * @property string $account_id
 */
final class GlAccountSetting extends BaseEnterpriseModel
{
    protected $table = 'ipp_gl_account_settings';
}
