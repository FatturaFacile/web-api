<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PartnerCommission extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'sub_partner_commissions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:4',
            'gross_billed_amount' => 'decimal:2',
            'commission_earned' => 'decimal:2',
        ];
    }

    protected static function newFactory(): ?Factory
    {
        $factoryName = 'Database\\Factories\\PartnerCommissionFactory';
        if (class_exists($factoryName)) {
            return $factoryName::new();
        }

        return null;
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
