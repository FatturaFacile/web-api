<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class DunningPolicy extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'sub_dunning_policies';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'soft_reminder_days' => 'integer',
            'formal_notice_days' => 'integer',
            'suspension_days' => 'integer',
            'termination_days' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function newFactory(): ?Factory
    {
        $factoryName = 'Database\\Factories\\DunningPolicyFactory';
        if (class_exists($factoryName)) {
            return $factoryName::new();
        }

        return null;
    }
}
