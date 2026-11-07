<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Associations\Enums\RegistryKind;
use Modules\Associations\Models\RegistryType;
use Modules\Associations\Models\SocialRole;

/**
 * The generic registry types and offices, shared by every company and read-only for them. Disciplines are left to each
 * company: their official codes depend on the body it is affiliated to.
 */
class AssociationsReferenceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RegistryKind::cases() as $order => $kind) {
            if ($kind !== RegistryKind::Custom) {
                RegistryType::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => null, 'code' => $kind->value],
                    ['name' => ucfirst(str_replace('_', ' ', $kind->value)), 'kind' => $kind, 'sort_order' => $order],
                );
            }
        }

        foreach (['president' => true, 'vice_president' => true, 'secretary' => true, 'treasurer' => true, 'board_member' => false, 'auditor' => false] as $code => $isSingleHolder) {
            SocialRole::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => null, 'code' => $code],
                ['name' => ucfirst(str_replace('_', ' ', $code)), 'is_single_holder' => $isSingleHolder],
            );
        }
    }
}
