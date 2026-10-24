<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionInterval;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'contact_id',
        'subscription_number',
        'name',
        'status',
        'interval',
        'interval_count',
        'currency',
        'start_date',
        'end_date',
        'next_billing_date',
        'last_billed_at',
        'auto_renew',
        'notify_days_before',
        'renewal_notified_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'auto_renew' => 'boolean',
            'end_date' => 'date',
            'interval' => SubscriptionInterval::class,
            'interval_count' => 'integer',
            'last_billed_at' => 'datetime',
            'next_billing_date' => 'date',
            'notify_days_before' => 'integer',
            'renewal_notified_at' => 'datetime',
            'start_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<SubscriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    /**
     * @return HasMany<SubscriptionRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(SubscriptionRun::class);
    }
}
