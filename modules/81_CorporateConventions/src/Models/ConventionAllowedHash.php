<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * SHA-256 of an employee badge, matricola or card of the partner's list: the identifier itself is never stored (GDPR / nLPD).
 *
 * @property string $convention_id
 * @property string $identifier_sha256
 * @property string|null $label_hint
 * @property bool $is_used
 * @property string|null $used_by_enrollment_id
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property string|null $deleted_by
 */
final class ConventionAllowedHash extends BaseEnterpriseModel
{
    protected $table = 'sales_convention_allowed_hashes';

    protected function casts(): array
    {
        return ['is_used' => 'boolean'];
    }
}
