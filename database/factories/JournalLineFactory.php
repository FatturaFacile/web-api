<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;

/**
 * @extends Factory<JournalLine>
 */
final class JournalLineFactory extends Factory
{
    protected $model = JournalLine::class;

    public function definition(): array
    {
        $tenantId = (string) Str::uuid7();
        $entry = JournalEntry::first() ?? JournalEntry::factory()->create(['tenant_id' => $tenantId]);
        $account = ChartOfAccount::first() ?? ChartOfAccount::factory()->create(['tenant_id' => $tenantId]);

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'journal_entry_id' => $entry->id,
            'posting_date' => date('Y-m-d'),
            'line_number' => 1,
            'account_id' => $account->id,
            'description' => $this->faker->sentence(),
            'debit_amount' => 100.00,
            'credit_amount' => 0.00,
            'currency' => 'EUR',
            'exchange_rate' => 1.000000,
            'amount_in_functional_currency' => 100.00,
            'is_cleared' => false,
        ];
    }
}
