<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FiscalRetail\Models\CommercialDocumentLine;

/**
 * @extends Factory<CommercialDocumentLine>
 */
final class CommercialDocumentLineFactory extends Factory
{
    protected $model = CommercialDocumentLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'commercial_document_id' => static fn (array $attributes): string => (string) CommercialDocumentFactory::new()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'source_line_id' => null,
            'description' => fake()->randomElement(['Caffè in grani 1 kg', 'Tazza in ceramica', 'Confezione regalo']),
            'quantity' => '2.0000',
            'unit_price' => '25.0000',
            'tax_rate' => '22.00',
            'vat_nature' => null,
            'net_amount' => '40.98',
            'tax_amount' => '9.02',
            'gross_amount' => '50.00',
        ];
    }
}
