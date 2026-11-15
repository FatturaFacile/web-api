<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Models;

use App\Models\LegalCase;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A party of a matter (client, opposing, related) recorded for the conflicts-of-interest search.
 *
 * @property string $id
 * @property ?string $tenant_id
 */
final class PracticeCaseParty extends Model
{
    use BelongsToTenant, HasUuidV7;

    /** @var list<string> */
    public const array Roles = ['client', 'opposing_party', 'related_party', 'witness'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'practice_case_parties';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }

    /**
     * @return BelongsTo<LegalCase, $this>
     */
    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'legal_case_id');
    }
}
