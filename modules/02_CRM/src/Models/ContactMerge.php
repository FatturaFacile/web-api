<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * History of a contact merge: which contact survived, which was merged into it, how many references of each table
 * were repointed, which were kept on purpose (issued fiscal documents) and a snapshot of the merged record, so that a
 * merge can always be explained after the fact.
 *
 * @property string $id
 * @property string $survivor_contact_id
 * @property string $merged_contact_id
 * @property array<string, int>|null $repointed
 * @property array<string, int>|null $retained
 * @property list<string>|null $filled_fields
 * @property array<string, mixed>|null $merged_snapshot
 * @property string|null $merged_by_user_id
 * @property Carbon|null $created_at
 */
final class ContactMerge extends BaseEnterpriseModel
{
    protected $table = 'crm_contact_merges';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'repointed' => 'array',
            'retained' => 'array',
            'filled_fields' => 'array',
            'merged_snapshot' => 'array',
        ];
    }
}
