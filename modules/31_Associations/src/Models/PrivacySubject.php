<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * A person or body with a role in the processing of personal data: controller, processor, data protection officer, or
 * a person authorized to process data under the authority of the controller. Identity and contact data are encrypted.
 *
 * @property string $id
 * @property string $role
 * @property string $name
 */
final class PrivacySubject extends AssociationRecord
{
    public const array Roles = ['controller', 'processor', 'dpo', 'authorized', 'other'];

    protected $table = 'assoc_privacy_subjects';

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'representative' => 'encrypted',
            'tax_code' => 'encrypted',
            'email' => 'encrypted',
            'address' => 'encrypted',
            'appointed_on' => 'date',
            'ended_on' => 'date',
        ];
    }
}
