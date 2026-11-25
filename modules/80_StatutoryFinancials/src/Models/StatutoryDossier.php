<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Domain\Enums\CashFlowMethod;
use Modules\StatutoryFinancials\Domain\Enums\DossierStatus;
use Modules\StatutoryFinancials\Domain\Enums\IncomeStatementMethod;
use Modules\StatutoryFinancials\Domain\Enums\RoundingMode;

/**
 * StatutoryFinancialStatementDossier: the aggregate of the statutory financial statements of one fiscal year.
 *
 * @property string $fiscal_year_id
 * @property string|null $comparative_prior_year_id
 * @property string|null $title
 * @property AccountingStandard $accounting_standard
 * @property DossierStatus $status
 * @property RoundingMode $rounding_to_unit_mode
 * @property IncomeStatementMethod $income_statement_method
 * @property CashFlowMethod $cash_flow_method
 * @property string $locale
 * @property string $currency
 * @property string|null $share_capital
 * @property array<string, mixed>|null $balance_sheet_lines
 * @property array<string, mixed>|null $income_statement_lines
 * @property array<string, mixed>|null $cash_flow_statement
 * @property array<string, mixed>|null $profit_allocation_proposal
 * @property array<string, mixed>|null $hidden_reserves_config
 * @property array<string, mixed>|null $options
 * @property array<string, mixed>|null $eligibility
 * @property array<string, mixed>|null $reclassification_report
 * @property array<string, mixed>|null $quadrature
 * @property Carbon|null $generated_at
 * @property Carbon|null $validated_at
 * @property Carbon|null $locked_at
 * @property Carbon|null $filed_at
 * @property string|null $filing_reference
 * @property string|null $updated_by
 */
final class StatutoryDossier extends BaseEnterpriseModel
{
    protected $table = 'sfs_dossiers';

    protected function casts(): array
    {
        return [
            'accounting_standard' => AccountingStandard::class,
            'status' => DossierStatus::class,
            'rounding_to_unit_mode' => RoundingMode::class,
            'income_statement_method' => IncomeStatementMethod::class,
            'cash_flow_method' => CashFlowMethod::class,
            'share_capital' => 'decimal:4',
            'balance_sheet_lines' => 'array',
            'income_statement_lines' => 'array',
            'cash_flow_statement' => 'array',
            'profit_allocation_proposal' => 'array',
            'hidden_reserves_config' => 'array',
            'options' => 'array',
            'eligibility' => 'array',
            'reclassification_report' => 'array',
            'quadrature' => 'array',
            'generated_at' => 'datetime',
            'validated_at' => 'datetime',
            'locked_at' => 'datetime',
            'filed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function comparativeYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'comparative_prior_year_id');
    }

    /**
     * @return HasMany<DossierLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DossierLine::class, 'dossier_id')->orderBy('statement')->orderBy('sort_order');
    }

    /**
     * @return HasOne<NotesWorkspace, $this>
     */
    public function notes(): HasOne
    {
        return $this->hasOne(NotesWorkspace::class, 'dossier_id');
    }

    /**
     * @return HasOne<DepositPackage, $this>
     */
    public function package(): HasOne
    {
        return $this->hasOne(DepositPackage::class, 'dossier_id');
    }

    /**
     * @return HasMany<Artifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class, 'dossier_id');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }
}
