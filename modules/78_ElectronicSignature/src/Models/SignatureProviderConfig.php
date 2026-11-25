<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\ElectronicSignature\Enums\ProviderJurisdiction;
use Modules\ElectronicSignature\Enums\ProviderType;

/**
 * The configuration of one signature provider of a company. The credentials (keys, secrets, client certificate, webhook
 * secret) are encrypted at rest and never serialised.
 *
 * @property string $name
 * @property ProviderType $provider
 * @property ProviderJurisdiction $jurisdiction
 * @property array<string, mixed>|null $credentials
 * @property string|null $certificate_path
 * @property bool $is_sandbox
 * @property bool $is_active
 * @property bool $is_default
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $last_tested_at
 * @property string|null $last_test_status
 * @property string|null $last_test_message
 */
final class SignatureProviderConfig extends BaseEnterpriseModel
{
    protected $table = 'signature_provider_configs';

    /**
     * @var list<string>
     */
    protected $hidden = ['credentials', 'certificate_path'];

    protected function casts(): array
    {
        return [
            'provider' => ProviderType::class,
            'jurisdiction' => ProviderJurisdiction::class,
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'is_sandbox' => 'boolean',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function credential(string $key, ?string $default = null): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }
}
