<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ElectronicSignature\Enums\Jurisdiction;
use Modules\ElectronicSignature\Enums\RoutingMode;
use Modules\ElectronicSignature\Enums\SignatureLevel;

/**
 * A reusable signature workflow. With `min_amount` the template is also a compliance rule: documents of its type from
 * that amount on need at least its level.
 *
 * @property string $name
 * @property string $document_type
 * @property SignatureLevel $required_level
 * @property Jurisdiction $jurisdiction
 * @property string|null $provider_config_id
 * @property RoutingMode $routing_mode
 * @property int $expiration_days
 * @property list<int>|null $reminder_days
 * @property string|null $min_amount
 * @property string|null $amount_currency
 * @property bool $is_active
 */
final class SignatureTemplate extends BaseEnterpriseModel
{
    protected $table = 'signature_templates';

    protected function casts(): array
    {
        return [
            'required_level' => SignatureLevel::class,
            'jurisdiction' => Jurisdiction::class,
            'routing_mode' => RoutingMode::class,
            'expiration_days' => 'integer',
            'reminder_days' => 'array',
            'min_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SignatureProviderConfig, $this>
     */
    public function providerConfig(): BelongsTo
    {
        return $this->belongsTo(SignatureProviderConfig::class, 'provider_config_id');
    }
}
