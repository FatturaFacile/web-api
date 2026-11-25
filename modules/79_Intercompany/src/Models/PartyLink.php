<?php

declare(strict_types=1);

namespace Modules\Intercompany\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Maps a group entity to the contact that stands for it in the books of another entity of the group.
 *
 * @property string $group_id
 * @property string $entity_tenant_id
 * @property string $counterparty_tenant_id
 * @property string $contact_id
 * @property string $role
 */
final class PartyLink extends BaseEnterpriseModel
{
    protected $table = 'ic_party_links';
}
