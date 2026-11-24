<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The outcome of a conflicts-of-interest search for a prospective or existing party.
 *
 * @property string $id
 * @property ?string $tenant_id
 */
final class PracticeConflictCheck extends Model
{
    use BelongsToTenant, HasUuidV7;

    /** @var list<string> */
    public const array Results = ['clear', 'review', 'conflict'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'practice_conflict_checks';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['matches' => 'array'];
    }
}
