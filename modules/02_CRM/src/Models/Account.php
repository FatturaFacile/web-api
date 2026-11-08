<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A CRM account: the commercial relationship (tier, owner, health, lifecycle) with a party whose master data lives
 * on the referenced contact (`contact_id`), the record shared with invoicing, quotes and orders.
 *
 * @property string|null $contact_id
 */
final class Account extends BaseEnterpriseModel
{
    protected $table = 'crm_accounts';

    protected function casts(): array
    {
        return [
            'annual_revenue' => 'decimal:2',
            'employee_count' => 'integer',
            'customer_health_index' => 'decimal:2',
            'last_activity_at' => 'datetime',
            'tags' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * The contact of the party master that owns this account's master data.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    public function childAccounts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    public function contactRoles(): HasMany
    {
        return $this->hasMany(ContactRole::class, 'account_id');
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'account_id');
    }

    public function healthProfile(): HasOne
    {
        return $this->hasOne(CustomerHealthProfile::class, 'account_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'related_id')->where('related_type', 'account');
    }
}
