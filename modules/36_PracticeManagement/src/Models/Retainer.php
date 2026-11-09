<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Models;

use App\Models\Contact;
use Carbon\CarbonImmutable;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An advance payment on account of fees (retainer) received from a client, optionally for one case. It is applied to
 * fee notes until exhausted. This is the register of the retainer; the money itself reaches the books through the bank
 * receipt in Treasury.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $client_id
 * @property ?string $legal_case_id
 * @property CarbonImmutable $received_on
 * @property string $amount
 * @property string $applied_amount
 * @property string $status
 */
final class Retainer extends Model
{
    use BelongsToTenant, HasUuidV7;

    /** @var list<string> */
    public const array Statuses = ['open', 'exhausted', 'void'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'practice_retainers';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['received_on' => 'immutable_date', 'amount' => 'decimal:2', 'applied_amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'client_id');
    }

    /**
     * @return HasMany<RetainerApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(RetainerApplication::class, 'retainer_id')->orderBy('applied_on');
    }
}
