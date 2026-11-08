<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A party linked to a contract besides its counterparty: a signatory, guarantor, subcontractor or any other role. It
 * points at a contact, a supplier or a user of the company, or carries the name of an external person.
 */
final class ContractParty extends BaseEnterpriseModel
{
    /**
     * Kinds of party and the table each one points at (external parties point at nothing).
     *
     * @var array<string, ?string>
     */
    public const array Types = ['contact' => 'contacts', 'supplier' => 'suppliers', 'user' => 'users', 'external' => null];

    /**
     * Roles a party can have on a contract.
     *
     * @var list<string>
     */
    public const array Roles = ['signatory', 'guarantor', 'subcontractor', 'witness', 'beneficiary', 'representative', 'other'];

    protected $table = 'contract_parties';
}
