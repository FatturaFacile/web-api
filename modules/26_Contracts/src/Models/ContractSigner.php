<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A person asked to sign. Only the hash of the personal signing token is stored.
 *
 * @property string $request_id
 * @property string $name
 * @property string $email
 * @property int $position
 * @property string $status
 * @property string|null $token_hash
 * @property Carbon|null $signed_at
 * @property string|null $signature_hash
 * @property string|null $decline_reason
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
