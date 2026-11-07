<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FiscalRetail\Models\CommercialDocument;

/**
 * @extends Factory<CommercialDocument>
 */
final class CommercialDocumentFactory extends Factory
{
    protected $model = CommercialDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequentialNumber = fake()->unique()->numberBetween(1, 99999);

        return [
            'tenant_id' => Tenant::factory(),
            'pos_sale_id' => null,
            'related_document_id' => null,
            'document_type' => 'sale',
            'fiscal_year' => 2026,
            'sequential_number' => $sequentialNumber,
            'document_number' => sprintf('DC-2026-%05d', $sequentialNumber),
            'issue_date' => '2026-11-05',
            'payment_method' => 'cash',
            'total_net' => '40.98',
            'total_tax' => '9.02',
            'total_gross' => '50.00',
            'gateway' => 'local',
            'gateway_status' => 'pending',
            'gateway_reference' => null,
        ];
    }
}
