<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\TenderManagement\Domain\Enums\EnvelopeType;
use Modules\TenderManagement\Domain\Enums\NoticeStatus;
use Modules\TenderManagement\Domain\Enums\ParticipationMode;
use Modules\TenderManagement\Domain\Enums\ParticipationStatus;
use Modules\TenderManagement\Domain\Support\Dec;
use Modules\TenderManagement\Events\TenderStatusChanged;
use Modules\TenderManagement\Exceptions\TenderException;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Models\TenderParticipation;

/**
 * The life cycle of a participation dossier: creation with the default envelope checklists, edits, the guarded status
 * transitions and the submission gate.
 */
final class ParticipationService
{
    public function __construct(private readonly EnvelopeService $envelopes) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(TenderNotice $notice, array $data, ?string $userId, CarbonImmutable $today): TenderParticipation
    {
        if ($notice->status !== NoticeStatus::Open) {
            throw new TenderException('notice_closed');
        }

        if ($notice->submission_peremptory_deadline_at !== null && $today->greaterThan(CarbonImmutable::parse($notice->submission_peremptory_deadline_at))) {
            throw new TenderException('deadline_passed');
        }

        if (TenderParticipation::query()->where('tender_id', $notice->id)->exists()) {
            throw new TenderException('participation_exists', 409);
        }

        return DB::transaction(function () use ($notice, $data, $userId): TenderParticipation {
            $participation = TenderParticipation::create([
                'tender_id' => $notice->id,
                'participation_mode' => $data['participation_mode'] ?? ParticipationMode::Single->value,
                'status' => ParticipationStatus::Scouting->value,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $participation->setRelation('notice', $notice);

            foreach (EnvelopeType::cases() as $type) {
                $this->envelopes->seed($participation, $type->value);
            }

            return $participation->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TenderParticipation $participation, array $data, ?string $userId): TenderParticipation
    {
        $allowed = ['participation_mode', 'rti_quota_percentage', 'partner_companies', 'notes', 'provisional_bid_bond_id', 'anac_contribution_receipt_id', 'technical_envelope_score_target'];
        $participation->fill(array_merge(array_intersect_key($data, array_flip($allowed)), ['updated_by' => $userId]))->save();

        return $participation->refresh();
    }

    public function transition(TenderParticipation $participation, ParticipationStatus $target, CarbonImmutable $today): TenderParticipation
    {
        if ($target === ParticipationStatus::Submitted) {
            return $this->submit($participation, $today);
        }

        if (! $participation->status->canMoveTo($target)) {
            throw new TenderException('invalid_transition', 422, ['from' => $participation->status->value, 'to' => $target->value]);
        }

        return $this->apply($participation, $target);
    }

    /**
     * Lodges the dossier: only from the envelope preparation, before the peremptory deadline and with every blocking finding resolved.
     */
    public function submit(TenderParticipation $participation, CarbonImmutable $today): TenderParticipation
    {
        if (! $participation->status->canMoveTo(ParticipationStatus::Submitted)) {
            throw new TenderException('invalid_transition', 422, ['from' => $participation->status->value, 'to' => ParticipationStatus::Submitted->value]);
        }

        $notice = $participation->notice;

        if ($notice->submission_peremptory_deadline_at !== null && $today->greaterThan(CarbonImmutable::parse($notice->submission_peremptory_deadline_at))) {
            throw new TenderException('deadline_passed');
        }

        $readiness = $this->envelopes->readiness($participation, $today);

        if (! $readiness['ready']) {
            throw new TenderException('readiness_blocked', 422, ['issues' => count($readiness['issues'])]);
        }

        $participation->forceFill(['submitted_at' => $today])->save();

        return $this->apply($participation, ParticipationStatus::Submitted);
    }

    /**
     * Stores the outcome of an economic offer simulation on the dossier.
     *
     * @param  array<string, mixed>  $result
     */
    public function storeOffer(TenderParticipation $participation, array $result, string $bidBasePercentage): TenderParticipation
    {
        $participation->forceFill([
            'economic_discount_percentage_offered' => $result['discount_percentage'],
            'offered_amount' => $result['offered_amount'],
            'estimated_total_cost' => $result['estimated_total_cost'],
            'net_margin_amount' => $result['net_margin_amount'],
            'company_safety_costs_declared' => $result['company_safety_costs_declared'],
            'company_labor_costs_declared' => $result['company_labor_costs_declared'],
            'bid_bond_base_percentage' => Dec::money($bidBasePercentage),
            'bid_bond_applied_reductions' => $result['bid_bond']['reductions'],
            'bid_bond_final_amount' => $result['bid_bond']['final_amount'],
            'anac_contribution_amount' => $result['anac_contribution_amount'],
            'technical_envelope_score_target' => $result['scoring']['technical_score'] ?? $participation->technical_envelope_score_target,
            'offer_simulation' => $result,
        ])->save();

        return $participation->refresh();
    }

    private function apply(TenderParticipation $participation, ParticipationStatus $target): TenderParticipation
    {
        return DB::transaction(function () use ($participation, $target): TenderParticipation {
            $from = $participation->status;
            $participation->forceFill(['status' => $target])->save();

            if ($target === ParticipationStatus::AwardedWon) {
                $participation->notice->forceFill(['status' => NoticeStatus::Awarded])->save();
            }

            event(new TenderStatusChanged((string) $participation->id, (string) $participation->tender_id, $from->value, $target->value, (string) $participation->tenant_id));

            return $participation->refresh();
        });
    }
}
