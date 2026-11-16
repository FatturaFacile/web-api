<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A person asked to sign. Only the hash of the personal signing token is stored.
 */
final class ContractSigner extends BaseEnterpriseModel
{
    protected $table = 'contract_signers';

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'signed_at' => 'datetime'];
    }
}
