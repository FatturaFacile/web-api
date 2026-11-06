<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MembershipType extends AssociationRecord
{
    protected $table = 'assoc_membership_types';

    protected function casts(): array
    {
        return ['requires_board_decision' => 'boolean', 'is_active' => 'boolean'];
    }

    public function registryType(): BelongsTo
    {
        return $this->belongsTo(RegistryType::class, 'registry_type_id');
    }

    public function feePlan(): BelongsTo
    {
        return $this->belongsTo(FeePlan::class, 'fee_plan_id');
    }
}
