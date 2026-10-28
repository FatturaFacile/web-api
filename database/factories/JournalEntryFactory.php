<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;

/**
 * @extends Factory<JournalEntry>
 */
final class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    public function definition(): array
    {
        $tenantId = (string) Str::uuid7();
        $period = FiscalPeriod::first() ?? FiscalPeriod::factory()->create(['tenant_id' => $tenantId]);

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => (string) Str::uuid7(),
            'fiscal_period_id' => $period->id,
            'entry_number' => 'JE-'.date('Ymd').'-'.strtoupper(Str::random(6)),
            'posting_date' => date('Y-m-d'),
            'document_date' => date('Y-m-d'),
            'ledger_type' => 'statutory',
            'journal_type' => 'standard',
            'description' => $this->faker->sentence(),
            'status' => 'posted',
            'total_amount' => 1000.00,
            'posted_at' => now(),
        ];
    }

    public function draft(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'posted_at' => null,
            'posted_by' => null,
        ]);
    }

    public function posted(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'posted',
            'posted_at' => now(),
        ]);
    }

    public function reversed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'reversed',
            'reversed_at' => now(),
            'reversal_date' => date('Y-m-d'),
        ]);
    }

    public function closed(): self
    {
        return $this->state(fn (array $attributes) => [
            'journal_type' => 'closing',
            'status' => 'posted',
            'posted_at' => now(),
        ]);
    }

    public function adjusting(): self
    {
        return $this->state(fn (array $attributes) => [
            'journal_type' => 'adjusting',
            'status' => 'posted',
            'posted_at' => now(),
        ]);
    }

    public function approved(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'posted',
            'posted_at' => now(),
        ]);
    }

    public function pendingApproval(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending_approval',
            'posted_at' => null,
        ]);
    }

    public function yearEnd(): self
    {
        return $this->state(fn (array $attributes) => [
            'journal_type' => 'closing',
            'description' => 'Year-End Closing Journal',
            'status' => 'posted',
            'posted_at' => now(),
        ]);
    }
}
