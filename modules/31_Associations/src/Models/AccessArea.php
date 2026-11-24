<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Modules\Associations\Enums\CertificateKind;

/**
 * A controlled area with the checks its readers apply.
 */
final class AccessArea extends AssociationRecord
{
    protected $table = 'assoc_access_areas';

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'check_fee' => 'boolean',
            'check_certificate' => 'boolean',
            'required_certificate' => CertificateKind::class,
            'check_course' => 'boolean',
            'course_margin_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
