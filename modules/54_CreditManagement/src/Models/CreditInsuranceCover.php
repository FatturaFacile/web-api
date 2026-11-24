<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The cover the insurer grants on one customer under a policy.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $policy_id
 * @property string $customer_id
 * @property string $insured_limit
 * @property string $status
 * @property Carbon|null $granted_on
 * @property Carbon|null $expires_on
 * @property string|null $notes
 */
final class CreditInsuranceCover extends BaseEnterpriseModel
{
    public const string Requested = 'requested';

    public const string Granted = 'granted';

    public const string Refused = 'refused';

    public const string Withdrawn = 'withdrawn';

    /** @var list<string> */
    public const array Statuses = [self::Requested, self::Granted, self::Refused, self::Withdrawn];

    protected $table = 'credit_insurance_covers';

    protected function casts(): array
    {
        return [
            'insured_limit' => 'decimal:2',
            'granted_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<CreditInsurancePolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(CreditInsurancePolicy::class, 'policy_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }
}
