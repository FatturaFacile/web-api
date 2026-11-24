<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AgentCommissions\Models\FiscalParameter;

/**
 * Statutory defaults of the agent commission module (global rows, tenant_id null), idempotent. A company overrides any
 * of them with a tenant row; the engine always reads the latest year not after the one it computes.
 *
 * The figures are the configured defaults for 2024 onwards and must be reviewed every January against the yearly
 * Enasarco circular (rates, massimale, minimale) and the tax rules: nothing in the business logic depends on them.
 */
final class AgentCommissionsReferenceSeeder extends Seeder
{
    /**
     * FIRR brackets, progressive: the share of the yearly commission up to each ceiling accrues at its rate (%).
     *
     * @var list<array{up_to: int|null, rate: int}>
     */
    private const array FirrBrackets = [
        ['up_to' => 12500, 'rate' => 4],
        ['up_to' => 20000, 'rate' => 2],
        ['up_to' => 32000, 'rate' => 1],
        ['up_to' => null, 'rate' => 0],
    ];

    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            FiscalParameter::updateOrCreateGlobal(
                ['code' => $definition['code'], 'mandate_type' => $definition['mandate_type'], 'year' => $definition['year']],
                ['value' => $definition['value'], 'payload' => $definition['payload'], 'label_key' => 'agentCommissions.fiscal.'.$definition['code']],
            );
        }
    }

    /**
     * @return list<array{code: string, mandate_type: string|null, year: int|null, value: string|null, payload: array<int, mixed>|null}>
     */
    private function definitions(): array
    {
        $rows = [];
        $perMandate = [
            'enasarco_agent_rate' => ['single' => '8.75', 'multi' => '8.75'],
            'enasarco_company_rate' => ['single' => '8.75', 'multi' => '8.75'],
            'enasarco_massimale' => ['single' => '46000', 'multi' => '28000'],
            'enasarco_minimale' => ['single' => '878', 'multi' => '439'],
        ];

        foreach ($perMandate as $code => $values) {
            foreach ($values as $mandate => $value) {
                $rows[] = ['code' => $code, 'mandate_type' => $mandate, 'year' => 2024, 'value' => $value, 'payload' => null];
            }
        }

        foreach (['single', 'multi'] as $mandate) {
            $rows[] = ['code' => 'firr_brackets', 'mandate_type' => $mandate, 'year' => 2024, 'value' => null, 'payload' => self::FirrBrackets];
        }

        foreach ([
            'withholding_rate' => '23',
            'withholding_base_with_organization' => '50',
            'withholding_base_without_organization' => '20',
            'vat_rate' => '22',
        ] as $code => $value) {
            $rows[] = ['code' => $code, 'mandate_type' => null, 'year' => 2024, 'value' => $value, 'payload' => null];
        }

        return $rows;
    }
}
