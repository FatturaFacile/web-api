<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\PracticeManagement\Models\PeriodClosing;

/**
 * @extends Factory<PeriodClosing>
 */
final class PeriodClosingFactory extends Factory
{
    protected $model = PeriodClosing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'client_id' => static fn (array $attributes): string => (string) Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => 'open',
            'checklist' => [
                ['key' => 'bank_reconciliation', 'label' => 'Riconciliazione bancaria', 'done' => false],
                ['key' => 'vat_settlement', 'label' => 'Liquidazione IVA', 'done' => false],
                ['key' => 'payroll', 'label' => 'Registrazione paghe', 'done' => false],
            ],
            'notes' => null,
            'closed_by' => null,
            'closed_at' => null,
        ];
    }
}
