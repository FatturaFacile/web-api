<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\TenderManagement\Domain\Enums\ParticipationMode;
use Modules\TenderManagement\Domain\Enums\ParticipationStatus;

/**
 * The dossier with which the company takes part in a tender (TenderParticipationDossier): partners, Go/No-Go score, bid bond,
 * the three envelopes, the economic offer and the conversion into a contract and a project.
 *
 * @property string $tender_id
 * @property ParticipationMode $participation_mode
 * @property string|null $rti_quota_percentage
 * @property list<array<string, mixed>>|null $partner_companies
 * @property array<string, mixed>|null $go_no_go_score
 * @property string|null $provisional_bid_bond_id
 * @property string|null $bid_bond_base_percentage
 * @property list<array<string, mixed>>|null $bid_bond_applied_reductions
 * @property string|null $bid_bond_final_amount
 * @property string|null $anac_contribution_receipt_id
 * @property string|null $anac_contribution_amount
 * @property list<array<string, mixed>>|null $administrative_envelope_docs
 * @property list<array<string, mixed>>|null $technical_envelope_docs
 * @property string|null $technical_envelope_score_target
 * @property list<array<string, mixed>>|null $economic_envelope_docs
 * @property string|null $economic_discount_percentage_offered
 * @property string|null $offered_amount
 * @property string|null $estimated_total_cost
 * @property string|null $net_margin_amount
 * @property string|null $company_safety_costs_declared
 * @property string|null $company_labor_costs_declared
 * @property array<string, mixed>|null $offer_simulation
 * @property array<string, mixed>|null $edgue_data
 * @property Carbon|null $submitted_at
 * @property ParticipationStatus $status
 * @property string|null $converted_contract_id
 * @property string|null $converted_project_job_id
 * @property Carbon|null $converted_at
 * @property string|null $notes
 */
final class TenderParticipation extends BaseEnterpriseModel
{
    protected $table = 'tnd_participations';

    protected function casts(): array
    {
        return [
            'participation_mode' => ParticipationMode::class,
            'status' => ParticipationStatus::class,
            'partner_companies' => 'array',
            'go_no_go_score' => 'array',
            'bid_bond_applied_reductions' => 'array',
            'administrative_envelope_docs' => 'array',
            'technical_envelope_docs' => 'array',
            'economic_envelope_docs' => 'array',
            'offer_simulation' => 'array',
            'edgue_data' => 'array',
            'rti_quota_percentage' => 'decimal:2',
            'bid_bond_base_percentage' => 'decimal:2',
            'bid_bond_final_amount' => 'decimal:2',
            'anac_contribution_amount' => 'decimal:2',
            'technical_envelope_score_target' => 'decimal:2',
            'economic_discount_percentage_offered' => 'decimal:4',
            'offered_amount' => 'decimal:2',
            'estimated_total_cost' => 'decimal:2',
            'net_margin_amount' => 'decimal:2',
            'company_safety_costs_declared' => 'decimal:2',
            'company_labor_costs_declared' => 'decimal:2',
            'submitted_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TenderNotice, $this>
     */
    public function notice(): BelongsTo
    {
        return $this->belongsTo(TenderNotice::class, 'tender_id');
    }

    /**
     * The documents of an envelope (administrative, technical or economic).
     *
     * @return list<array<string, mixed>>
     */
    public function envelopeDocs(string $envelope): array
    {
        return array_values($this->{$envelope.'_envelope_docs'} ?? []);
    }
}
