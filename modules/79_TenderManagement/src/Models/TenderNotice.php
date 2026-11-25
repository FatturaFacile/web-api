<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\TenderManagement\Domain\Enums\AwardCriterion;
use Modules\TenderManagement\Domain\Enums\FormulaType;
use Modules\TenderManagement\Domain\Enums\Jurisdiction;
use Modules\TenderManagement\Domain\Enums\NoticeStatus;
use Modules\TenderManagement\Domain\Enums\ProcedureType;

/**
 * A public or private tender notice (PublicOrPrivateTenderNotice): simap.ch LAPub / IVöB, ANAC / MePA D.Lgs. 36/2023,
 * EU TED or a private request for proposal.
 *
 * @property string $title
 * @property string|null $description
 * @property Jurisdiction $jurisdiction
 * @property ProcedureType $procedure_type
 * @property string $contracting_authority_name
 * @property string|null $contracting_authority_contact_id
 * @property string|null $simap_project_number
 * @property string|null $cig_code_it
 * @property string|null $cup_code_it
 * @property string|null $rup_name
 * @property string|null $dedicated_bank_account_it
 * @property list<string>|null $cpv_category_codes
 * @property AwardCriterion $award_criterion
 * @property FormulaType|null $formula_type
 * @property string $currency
 * @property string $base_auction_amount
 * @property string $non_subject_safety_costs_amount
 * @property string $estimated_labor_cost_amount
 * @property string|null $technical_max_score
 * @property string|null $economic_max_score
 * @property list<array{category: string, classification: string}>|null $required_soa_categories
 * @property string|null $required_min_turnover
 * @property int $turnover_reference_years
 * @property int $required_similar_projects_count
 * @property string|null $required_similar_projects_amount
 * @property list<string>|null $required_certifications
 * @property Carbon|null $publication_date
 * @property Carbon|null $clarification_deadline_at
 * @property Carbon|null $submission_peremptory_deadline_at
 * @property NoticeStatus $status
 */
final class TenderNotice extends BaseEnterpriseModel
{
    protected $table = 'tnd_notices';

    protected function casts(): array
    {
        return [
            'jurisdiction' => Jurisdiction::class,
            'procedure_type' => ProcedureType::class,
            'award_criterion' => AwardCriterion::class,
            'formula_type' => FormulaType::class,
            'status' => NoticeStatus::class,
            'cpv_category_codes' => 'array',
            'required_soa_categories' => 'array',
            'required_certifications' => 'array',
            'base_auction_amount' => 'decimal:2',
            'non_subject_safety_costs_amount' => 'decimal:2',
            'estimated_labor_cost_amount' => 'decimal:2',
            'technical_max_score' => 'decimal:2',
            'economic_max_score' => 'decimal:2',
            'required_min_turnover' => 'decimal:2',
            'required_similar_projects_amount' => 'decimal:2',
            'turnover_reference_years' => 'integer',
            'required_similar_projects_count' => 'integer',
            'publication_date' => 'date',
            'clarification_deadline_at' => 'datetime',
            'submission_peremptory_deadline_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<TenderParticipation, $this>
     */
    public function participations(): HasMany
    {
        return $this->hasMany(TenderParticipation::class, 'tender_id');
    }

    public function isItalian(): bool
    {
        return $this->jurisdiction === Jurisdiction::ItalianAnac;
    }

    public function isSwiss(): bool
    {
        return $this->jurisdiction === Jurisdiction::SwissSimap;
    }
}
