<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Contracts\Models\ContractDocumentVersion;
use Modules\Contracts\Models\ContractSignatureRequest;

/**
 * @extends Factory<ContractSignatureRequest>
 */
final class ContractSignatureRequestFactory extends Factory
{
    protected $model = ContractSignatureRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'contract_kind' => 'customer',
            'contract_id' => (string) Str::uuid7(),
            'document_version_id' => ContractDocumentVersion::factory(),
            'provider' => 'internal',
            'status' => 'draft',
            'order_mode' => 'sequential',
            'document_hash' => hash('sha256', 'Initial text'),
        ];
    }
}
