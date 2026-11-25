<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\TenderManagement\Domain\Enums\AwardCriterion;
use Modules\TenderManagement\Domain\Enums\EnvelopeType;
use Modules\TenderManagement\Domain\Enums\FormulaType;
use Modules\TenderManagement\Domain\Enums\Jurisdiction;
use Modules\TenderManagement\Domain\Enums\ParticipationMode;
use Modules\TenderManagement\Domain\Enums\ParticipationStatus;
use Modules\TenderManagement\Domain\Enums\ProcedureType;
use Modules\TenderManagement\Domain\Enums\VaultDocType;
use Modules\TenderManagement\Domain\Enums\VerificationStatus;
use Modules\TenderManagement\Domain\Support\Dec;
use Modules\TenderManagement\Domain\Support\SoaClassification;
use Modules\TenderManagement\Http\Controllers\Concerns\InteractsWithTenders;
use Modules\TenderManagement\Models\TenderAlert;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Models\TenderParticipation;
use Modules\TenderManagement\Services\VaultService;
use Modules\TenderManagement\Services\VaultStatusResolver;
use Modules\TenderManagement\Strategies\TenderStrategyRegistry;
use OpenApi\Attributes as OA;

/**
 * Reference lists for the forms and the dashboard of the tender module.
 */
final class LookupController extends Controller
{
    use ApiResponse;
    use InteractsWithTenders;

    #[OA\Get(path: '/api/v1/tender-management/lookups', operationId: 'tenderManagementLookups', summary: 'Enumerations, SOA categories, bid bond reductions and envelope templates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function lookups(TenderStrategyRegistry $strategies): JsonResponse
    {
        Gate::authorize('viewAny', TenderNotice::class);

        $transitions = [];
        foreach (ParticipationStatus::cases() as $status) {
            $transitions[$status->value] = array_map(static fn (ParticipationStatus $next): string => $next->value, $status->transitions());
        }

        return $this->successResponse([
            'jurisdictions' => Jurisdiction::values(),
            'procedure_types' => ProcedureType::values(),
            'award_criteria' => AwardCriterion::values(),
            'formula_types' => FormulaType::values(),
            'participation_modes' => ParticipationMode::values(),
            'participation_statuses' => array_map(static fn (ParticipationStatus $status): string => $status->value, ParticipationStatus::cases()),
            'verification_statuses' => VerificationStatus::values(),
            'vault_doc_types' => array_map(static fn (VaultDocType $type): array => [
                'value' => $type->value,
                'jurisdiction' => $type->isItalian() ? 'IT' : 'CH',
                'bond_reduction_key' => $type->bondReductionKey(),
                'max_age_months' => $type->maxAgeMonths(),
            ], VaultDocType::cases()),
            'envelopes' => array_map(static fn (EnvelopeType $type): string => $type->value, EnvelopeType::cases()),
            'transitions' => $transitions,
            'soa_categories' => SoaClassification::categories(),
            'soa_classifications' => SoaClassification::Order,
            'bid_bond' => [
                'allowed_base_percentages' => config('tender_management.bid_bond.allowed_base_percentages'),
                'reductions' => config('tender_management.bid_bond.reductions'),
            ],
            'scoring' => [
                'technical_max_default' => config('tender_management.scoring.technical_max_default'),
                'economic_max_default' => config('tender_management.scoring.economic_max_default'),
            ],
            'envelope_templates' => [
                'IT' => $strategies->for(Jurisdiction::ItalianAnac)->envelopeTemplate(),
                'CH' => $strategies->for(Jurisdiction::SwissSimap)->envelopeTemplate(),
                'GENERIC' => $strategies->for(Jurisdiction::PrivateRfp)->envelopeTemplate(),
            ],
        ]);
    }

    #[OA\Get(path: '/api/v1/tender-management/dashboard', operationId: 'tenderManagementDashboard', summary: 'Pipeline counts, upcoming deadlines, vault health and open alerts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Tender Management'], responses: [new OA\Response(response: 200, description: 'Dashboard')])]
    public function dashboard(VaultService $vault, VaultStatusResolver $resolver): JsonResponse
    {
        Gate::authorize('viewAny', TenderNotice::class);

        $today = CarbonImmutable::today();
        $participations = TenderParticipation::query()->with('notice')->get();
        $counts = array_fill_keys(array_map(static fn (ParticipationStatus $status): string => $status->value, ParticipationStatus::cases()), 0);
        $deadlines = [];

        foreach ($participations as $participation) {
            $counts[$participation->status->value]++;

            if (! in_array($participation->status, [ParticipationStatus::Scouting, ParticipationStatus::GoApproved, ParticipationStatus::PreparingEnvelopes], true)) {
                continue;
            }

            foreach (['submission' => $participation->notice->submission_peremptory_deadline_at, 'clarification' => $participation->notice->clarification_deadline_at] as $kind => $at) {
                if ($at !== null && $at->isFuture()) {
                    $deadlines[] = ['participation_id' => $participation->id, 'notice_id' => $participation->tender_id, 'title' => $participation->notice->title, 'jurisdiction' => $participation->notice->jurisdiction->value, 'deadline_at' => $at->toIso8601String(), 'kind' => $kind];
                }
            }
        }

        usort($deadlines, static fn (array $a, array $b): int => strcmp($a['deadline_at'], $b['deadline_at']));
        $health = ['valid' => 0, 'expiring_soon' => 0, 'expired' => 0];

        foreach ($vault->activeDocuments() as $document) {
            $status = $resolver->statusAt($document, $today);
            $key = match ($status) {
                VerificationStatus::Expired => 'expired',
                VerificationStatus::ExpiringSoon, VerificationStatus::PendingRenewal => 'expiring_soon',
                default => 'valid',
            };
            $health[$key]++;
        }

        $sum = fn (ParticipationStatus ...$statuses): string => $participations->filter(fn (TenderParticipation $participation): bool => in_array($participation->status, $statuses, true))
            ->reduce(fn (string $carry, TenderParticipation $participation): string => Dec::add($carry, $participation->offered_amount ?? $participation->notice->base_auction_amount), '0');

        return $this->successResponse([
            'pipeline_counts' => $counts,
            'upcoming_deadlines' => array_slice($deadlines, 0, 10),
            'vault' => $health,
            'open_alerts' => TenderAlert::query()->whereNull('acknowledged_at')->count(),
            'won_value' => Dec::money($sum(ParticipationStatus::AwardedWon)),
            'submitted_value' => Dec::money($sum(ParticipationStatus::Submitted)),
        ]);
    }
}
