<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Base of the records with the personal data of a candidate. It does not extend BaseEnterpriseModel on purpose: the audit
 * ledger is immutable, and these records must be really gone when their retention ends.
 */
abstract class CandidateRecord extends Model
{
    use BelongsToTenant, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'created_at', 'updated_at'];
}
