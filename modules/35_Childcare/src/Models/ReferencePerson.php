<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

/**
 * A reference person of a child: guardian, relative or authorised pick-up. A guardian with a portal contact uses the
 * family portal.
 *
 * @property string $id
 * @property string $child_id
 * @property string $full_name
 * @property bool $is_guardian
 * @property bool $can_pick_up
 * @property ?string $portal_contact_id
 */
final class ReferencePerson extends ChildcareRecord
{
    protected $table = 'cc_reference_persons';

    protected function casts(): array
    {
        return ['full_name' => 'encrypted', 'phone' => 'encrypted', 'email' => 'encrypted', 'is_guardian' => 'boolean', 'can_pick_up' => 'boolean'];
    }
}
