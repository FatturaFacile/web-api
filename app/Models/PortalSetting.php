<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * White-label and security settings of the customer portal of a tenant.
 */
class PortalSetting extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'portal_settings';

    protected $fillable = [
        'tenant_id',
        'portal_slug',
        'custom_domain',
        'logo_url',
        'favicon_url',
        'primary_color',
        'secondary_color',
        'support_email',
        'privacy_policy_url',
        'require_otp',
        'allow_partial_payments',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'require_otp' => 'boolean',
            'allow_partial_payments' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
