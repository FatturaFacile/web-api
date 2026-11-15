<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Base of the survey response records. It deliberately has no audit trail (the ledger would record the acting user and
 * the IP address next to the answers, which would defeat anonymity), no soft delete and no timestamps.
 */
abstract class AnonymousRecord extends Model
{
    use BelongsToTenant, HasUuidV7;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];
}
