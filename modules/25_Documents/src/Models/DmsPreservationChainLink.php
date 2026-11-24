<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use App\Support\Database\Concerns\ProtectsRecords;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One link of the hash chain over the closed preservation packages of a company. Links are append-only.
 *
 * @property string $id
 * @property int $sequence
 * @property string $package_id
 * @property string $package_hash
 * @property string $previous_link_hash
 * @property string $link_hash
 * @property ?string $anchored_by
 */
final class DmsPreservationChainLink extends BaseEnterpriseModel
{
    use ProtectsRecords;

    protected $table = 'dms_preservation_chain_links';

    protected function recordProtectionViolation(string $operation): ?string
    {
        return $operation === 'create' ? null : 'Preservation chain links are append-only and cannot be updated or deleted.';
    }

    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }
}
