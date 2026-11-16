<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Contracts\Models\ContractDocumentVersion;

/**
 * @extends Factory<ContractDocumentVersion>
 */
final class ContractDocumentVersionFactory extends Factory
{
    protected $model = ContractDocumentVersion::class;

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
            'version_no' => 1,
            'body' => 'Initial text',
            'source' => 'edit',
            'body_hash' => hash('sha256', 'Initial text'),
        ];
    }
}
