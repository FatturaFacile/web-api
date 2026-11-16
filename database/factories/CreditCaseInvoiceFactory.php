<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CreditManagement\Models\CreditCaseInvoice;
use Modules\CreditManagement\Models\CreditCollectionCase;

/**
 * @extends Factory<CreditCaseInvoice>
 */
final class CreditCaseInvoiceFactory extends Factory
{
    protected $model = CreditCaseInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'case_id' => CreditCollectionCase::factory(),
            'invoice_id' => (string) Str::uuid7(),
            'invoice_number' => 'INV-'.Str::upper(Str::random(6)),
            'due_on' => now()->subDays(10)->toDateString(),
            'outstanding_amount' => '1000.00',
        ];
    }
}
