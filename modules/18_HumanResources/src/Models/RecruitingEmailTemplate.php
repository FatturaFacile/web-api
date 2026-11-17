<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Tenant-customised email template sent to candidates entering a stage, per locale.
 *
 * @property string $id
 * @property string $stage
 * @property string $locale
 * @property string $subject
 * @property string $body
 * @property bool $is_enabled
 */
final class RecruitingEmailTemplate extends BaseEnterpriseModel
{
    protected $table = 'rec_email_templates';

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }
}
