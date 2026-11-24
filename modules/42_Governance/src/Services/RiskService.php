<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Governance\Models\GovControl;
use Modules\Governance\Models\GovFinding;
use Modules\Governance\Models\GovRisk;
use Modules\Governance\Models\GovRiskControl;

/**
 * Life of a risk of the register: scoring (likelihood x impact on a 1-5 scale), residual score after treatment, links
 * to the controls that mitigate it, and the status flow identified, assessed, treating, accepted and closed.
 *
 * Rules: the residual score cannot exceed the inherent one, a risk goes into treatment only when an active control
 * mitigates it, and accepting a risk needs a justification (the authority to accept is checked by the policy).
 */
final readonly class RiskService
{
    /** Months until the next review of a risk, by its current rating. */
    private const array ReviewMonthsByRating = ['critical' => 3, 'high' => 6, 'medium' => 12, 'low' => 12];

    public function __construct(private GovernanceNumbers $numbers, private GovernanceLedger $ledger) {}

    public static function score(int $likelihood, int $impact): int
    {
        return $likelihood * $impact;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): GovRisk
    {
        $scores = $this->scores($data, null);

        return DB::transaction(function () use ($tenantId, $data, $scores): GovRisk {
            $risk = GovRisk::create([
                'tenant_id' => $tenantId,
                'code' => $this->numbers->next('gov_risks', 'RSK', $tenantId),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'],
                'treatment' => $data['treatment'] ?? null,
                'treatment_notes' => $data['treatment_notes'] ?? null,
                'owner_id' => $data['owner_id'] ?? null,
                'status' => 'identified',
                'next_review_date' => $data['next_review_date'] ?? $this->defaultReviewDate($scores),
            ] + $scores);

            $this->ledger->record('GOV_RISK_CREATED', $risk, ['inherent_score' => $risk->inherent_score, 'rating' => GovRisk::ratingOf($risk->inherent_score)]);

            return $risk;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GovRisk $risk, array $data): GovRisk
    {
        if ($risk->status === 'closed') {
            throw ValidationException::withMessages(['status' => [__('governance_errors.risk.closed')]]);
        }

        $scores = $this->scores($data, $risk);
        $fields = array_intersect_key($data, array_flip(['title', 'description', 'category', 'treatment', 'treatment_notes', 'owner_id', 'next_review_date']));

        $risk->update($fields + $scores);

        return $risk->refresh();
    }

    public function transition(GovRisk $risk, string $to, ?string $notes, ?string $actorId): GovRisk
    {
        return DB::transaction(function () use ($risk, $to, $notes, $actorId): GovRisk {
            $locked = GovRisk::query()->whereKey($risk->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            StatusMachine::assertAllowed(GovRisk::Transitions, $from, $to);

            $changes = ['status' => $to];

            switch ($to) {
                case 'assessed':
                    if ($locked->owner_id === null) {
                        throw ValidationException::withMessages(['owner_id' => [__('governance_errors.risk.owner_required')]]);
                    }
                    $changes['closed_at'] = null;

                    break;
                case 'treating':
                    if (! $this->hasActiveControl($locked)) {
                        throw ValidationException::withMessages(['status' => [__('governance_errors.risk.active_control_required')]]);
                    }
                    $changes['treatment'] = $locked->treatment === null || $locked->treatment === 'accept' ? 'mitigate' : $locked->treatment;

                    break;
                case 'accepted':
                    $changes['treatment_notes'] = StatusMachine::requireNotes($notes, 'governance_errors.risk.acceptance_notes_required');
                    $changes['treatment'] = 'accept';
                    if ($locked->residual_score === null) {
                        $changes['residual_likelihood'] = $locked->likelihood;
                        $changes['residual_impact'] = $locked->impact;
                        $changes['residual_score'] = $locked->inherent_score;
                    }

                    break;
                case 'closed':
                    $changes['closed_at'] = now();

                    break;
            }

            if ($notes !== null && $notes !== '' && ! isset($changes['treatment_notes'])) {
                $changes['treatment_notes'] = $notes;
            }

            $locked->update($changes);
            $this->ledger->record('GOV_RISK_TRANSITIONED', $locked, ['from' => $from, 'to' => $to, 'actor_id' => $actorId, 'residual_score' => $locked->residual_score]);

            return $locked->refresh();
        });
    }

    /**
     * Replaces the controls linked to the risk.
     *
     * @param  list<string>  $controlIds
     */
    public function syncControls(GovRisk $risk, array $controlIds): GovRisk
    {
        DB::transaction(function () use ($risk, $controlIds): void {
            $current = GovRiskControl::query()->where('risk_id', $risk->id)->get()->keyBy('control_id');

            foreach ($current as $controlId => $link) {
                if (! in_array($controlId, $controlIds, true)) {
                    $link->delete();
                }
            }

            foreach ($controlIds as $controlId) {
                if (! $current->has($controlId)) {
                    GovRiskControl::create(['tenant_id' => $risk->tenant_id, 'risk_id' => $risk->id, 'control_id' => $controlId]);
                }
            }

            $this->ledger->record('GOV_RISK_CONTROLS_CHANGED', $risk, ['controls' => count($controlIds)]);
        });

        return $risk->refresh();
    }

    /**
     * Deletes a risk that no open finding refers to.
     */
    public function delete(GovRisk $risk): void
    {
        if (GovFinding::query()->where('risk_id', $risk->id)->whereIn('status', GovFinding::OpenStatuses)->exists()) {
            throw ValidationException::withMessages(['risk_id' => [__('governance_errors.risk.open_findings')]]);
        }

        DB::transaction(function () use ($risk): void {
            GovRiskControl::query()->where('risk_id', $risk->id)->get()->each->delete();
            $risk->delete();
            $this->ledger->record('GOV_RISK_DELETED', $risk);
        });
    }

    private function hasActiveControl(GovRisk $risk): bool
    {
        return GovControl::query()
            ->where('status', 'active')
            ->whereIn('id', GovRiskControl::query()->where('risk_id', $risk->id)->select('control_id'))
            ->exists();
    }

    /**
     * Inherent and residual scores of the payload merged over the current risk, validated against each other.
     *
     * @param  array<string, mixed>  $data
     * @return array{likelihood: int, impact: int, inherent_score: int, residual_likelihood: int|null, residual_impact: int|null, residual_score: int|null}
     */
    private function scores(array $data, ?GovRisk $current): array
    {
        $likelihood = (int) ($data['likelihood'] ?? $current?->likelihood);
        $impact = (int) ($data['impact'] ?? $current?->impact);
        $residualLikelihood = array_key_exists('residual_likelihood', $data) ? $data['residual_likelihood'] : $current?->residual_likelihood;
        $residualImpact = array_key_exists('residual_impact', $data) ? $data['residual_impact'] : $current?->residual_impact;

        if ($residualLikelihood !== null && $residualImpact !== null && ($residualLikelihood > $likelihood || $residualImpact > $impact)) {
            throw ValidationException::withMessages(['residual_likelihood' => [__('governance_errors.risk.residual_exceeds_inherent')]]);
        }

        return [
            'likelihood' => $likelihood,
            'impact' => $impact,
            'inherent_score' => self::score($likelihood, $impact),
            'residual_likelihood' => $residualLikelihood === null || $residualImpact === null ? null : (int) $residualLikelihood,
            'residual_impact' => $residualLikelihood === null || $residualImpact === null ? null : (int) $residualImpact,
            'residual_score' => $residualLikelihood === null || $residualImpact === null ? null : self::score((int) $residualLikelihood, (int) $residualImpact),
        ];
    }

    /**
     * @param  array{inherent_score: int, residual_score: int|null}  $scores
     */
    private function defaultReviewDate(array $scores): string
    {
        $rating = GovRisk::ratingOf($scores['residual_score'] ?? $scores['inherent_score']);

        return Carbon::today()->addMonthsNoOverflow(self::ReviewMonthsByRating[$rating])->toDateString();
    }
}
