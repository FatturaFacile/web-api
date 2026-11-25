<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Domain\Enums\CashFlowMethod;
use Modules\StatutoryFinancials\Domain\Enums\DossierStatus;
use Modules\StatutoryFinancials\Domain\Enums\IncomeStatementMethod;
use Modules\StatutoryFinancials\Domain\Enums\RoundingMode;
use Modules\StatutoryFinancials\Exceptions\StatutoryFinancialsException;
use Modules\StatutoryFinancials\Models\DepositPackage;
use Modules\StatutoryFinancials\Models\NotesWorkspace;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Strategies\StatutoryStrategyResolver;

/**
 * Lifecycle of the dossier aggregate: creation, settings, manual status transitions and deletion.
 */
final class DossierService
{
    public function __construct(private readonly StatutoryStrategyResolver $strategies) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $tenantId, ?string $userId): StatutoryDossier
    {
        $standard = AccountingStandard::from((string) $data['accounting_standard']);
        $strategy = $this->strategies->for($standard);
        $year = FiscalYear::query()->find($data['fiscal_year_id']);

        if ($year === null) {
            throw new StatutoryFinancialsException('fiscal_year_not_found', 404);
        }

        if (isset($data['comparative_prior_year_id']) && $data['comparative_prior_year_id'] === $data['fiscal_year_id']) {
            throw new StatutoryFinancialsException('comparative_year_same', 422);
        }

        $method = IncomeStatementMethod::tryFrom((string) ($data['income_statement_method'] ?? '')) ?? IncomeStatementMethod::TotalCost;

        if (! in_array($method, $strategy->incomeStatementMethods($standard), true)) {
            throw new StatutoryFinancialsException('income_statement_method_not_allowed', 422, ['method' => $method->value, 'standard' => $standard->value]);
        }

        $cashFlow = CashFlowMethod::tryFrom((string) ($data['cash_flow_method'] ?? '')) ?? CashFlowMethod::Indirect;

        return DB::transaction(function () use ($data, $tenantId, $userId, $standard, $year, $method, $cashFlow): StatutoryDossier {
            $dossier = StatutoryDossier::query()->create([
                'tenant_id' => $tenantId,
                'fiscal_year_id' => $year->id,
                'comparative_prior_year_id' => $data['comparative_prior_year_id'] ?? null,
                'title' => $data['title'] ?? null,
                'accounting_standard' => $standard,
                'status' => DossierStatus::Draft,
                'rounding_to_unit_mode' => RoundingMode::tryFrom((string) ($data['rounding_to_unit_mode'] ?? '')) ?? RoundingMode::ExactCents,
                'income_statement_method' => $method,
                'cash_flow_method' => $cashFlow,
                'locale' => $data['locale'] ?? ($standard->jurisdiction() === 'CH' ? 'de' : 'it'),
                'currency' => $data['currency'] ?? ($standard->jurisdiction() === 'CH' ? 'CHF' : 'EUR'),
                'share_capital' => $data['share_capital'] ?? null,
                'options' => $data['options'] ?? null,
                'profit_allocation_proposal' => $this->allocationInputs($data),
                'hidden_reserves_config' => $data['hidden_reserves_config'] ?? null,
                'created_by' => $userId,
            ]);

            DepositPackage::query()->create(['tenant_id' => $tenantId, 'dossier_id' => $dossier->id, 'validation_status' => 'not_run']);

            return $dossier;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StatutoryDossier $dossier, array $data, ?string $userId): StatutoryDossier
    {
        $this->assertEditable($dossier);
        $stale = false;

        foreach (['accounting_standard' => AccountingStandard::class, 'rounding_to_unit_mode' => RoundingMode::class, 'income_statement_method' => IncomeStatementMethod::class, 'cash_flow_method' => CashFlowMethod::class] as $key => $enum) {
            if (isset($data[$key])) {
                $value = $enum::from((string) $data[$key]);
                $stale = $stale || $dossier->{$key} !== $value;
                $dossier->{$key} = $value;
            }
        }

        $standard = $dossier->accounting_standard;
        $strategy = $this->strategies->for($standard);

        if (! in_array($dossier->income_statement_method, $strategy->incomeStatementMethods($standard), true)) {
            $dossier->income_statement_method = IncomeStatementMethod::TotalCost;
        }

        if (array_key_exists('comparative_prior_year_id', $data)) {
            if ($data['comparative_prior_year_id'] === $dossier->fiscal_year_id) {
                throw new StatutoryFinancialsException('comparative_year_same', 422);
            }

            $stale = $stale || $dossier->comparative_prior_year_id !== $data['comparative_prior_year_id'];
            $dossier->comparative_prior_year_id = $data['comparative_prior_year_id'];
        }

        foreach (['title', 'locale', 'currency', 'share_capital', 'options', 'hidden_reserves_config'] as $key) {
            if (array_key_exists($key, $data)) {
                $dossier->{$key} = $data[$key];
            }
        }

        if (array_intersect(['dividends', 'other_reserves', 'carried_forward'], array_keys($data)) !== []) {
            $dossier->profit_allocation_proposal = array_merge($dossier->profit_allocation_proposal ?? [], $this->allocationInputs($data));
        }

        if ($stale && $dossier->status !== DossierStatus::Draft) {
            $dossier->status = DossierStatus::Draft;
            $dossier->validated_at = null;
        }

        $dossier->updated_by = $userId;
        $dossier->save();

        return $dossier;
    }

    public function transition(StatutoryDossier $dossier, DossierStatus $target, ?string $reference): StatutoryDossier
    {
        if (! in_array($target, $dossier->status->manualTargets(), true)) {
            throw new StatutoryFinancialsException('transition_not_allowed', 422, ['from' => $dossier->status->value, 'to' => $target->value]);
        }

        if ($target === DossierStatus::Filed) {
            $package = $dossier->package;

            if ($package === null || $package->generated_xbrl_instance_file_id === null || $package->validation_status === 'invalid') {
                throw new StatutoryFinancialsException('filing_requires_valid_xbrl', 422);
            }

            $dossier->filed_at = now();
            $dossier->filing_reference = $reference;
        }

        if ($target === DossierStatus::Locked) {
            $dossier->locked_at = now();
        }

        if ($target === DossierStatus::Draft) {
            $dossier->locked_at = null;
            $dossier->validated_at = null;
        }

        $dossier->status = $target;
        $dossier->save();

        return $dossier;
    }

    public function delete(StatutoryDossier $dossier): void
    {
        $this->assertEditable($dossier);

        DB::transaction(function () use ($dossier): void {
            NotesWorkspace::query()->where('dossier_id', $dossier->id)->delete();
            DepositPackage::query()->where('dossier_id', $dossier->id)->delete();
            $dossier->delete();
        });
    }

    public function assertEditable(StatutoryDossier $dossier): void
    {
        if (! $dossier->isEditable()) {
            throw new StatutoryFinancialsException('dossier_locked', 409, ['status' => $dossier->status->value]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function allocationInputs(array $data): array
    {
        $inputs = [];

        foreach (['dividends', 'other_reserves', 'carried_forward'] as $key) {
            if (array_key_exists($key, $data)) {
                $inputs['input_'.$key] = (string) $data[$key];
            }
        }

        return $inputs;
    }
}
