<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Projects\Models\Project;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\TenderManagement\Domain\Enums\ParticipationStatus;
use Modules\TenderManagement\Domain\Support\Dec;
use Modules\TenderManagement\Events\TenderAwardConverted;
use Modules\TenderManagement\Exceptions\TenderException;
use Modules\TenderManagement\Models\TenderParticipation;
use Modules\TenderManagement\Strategies\TenderStrategyRegistry;

/**
 * Turns an awarded dossier into the customer contract (Mod 108, the service contract register) and the operational project
 * (Mod 065 commessa) in one transaction. The traceability references of the regime travel with them: CIG, CUP, RUP and the
 * dedicated bank account (L. 136/2010) for the FatturaPA e-invoices of an Italian tender, the simap.ch project number for a
 * Swiss one.
 */
final class AwardConversionService
{
    public function __construct(private readonly TenderStrategyRegistry $strategies) {}

    /**
     * @param  array{contact_id?: string|null, project_manager_user_id?: string|null, planned_start_date?: string|null, planned_end_date?: string|null, contract_title?: string|null}  $options
     * @return array{participation: TenderParticipation, contract: ServiceContract, project: Project, references: array<string, string|null>}
     */
    public function convert(TenderParticipation $participation, array $options, ?string $userId, CarbonImmutable $today): array
    {
        if ($participation->converted_contract_id !== null || $participation->converted_project_job_id !== null) {
            throw new TenderException('already_converted', 409);
        }

        if ($participation->status !== ParticipationStatus::AwardedWon) {
            throw new TenderException('not_awarded');
        }

        $notice = $participation->notice;
        $contactId = $options['contact_id'] ?? $notice->contracting_authority_contact_id ?? throw new TenderException('contracting_authority_contact_required');
        $managerId = $options['project_manager_user_id'] ?? $userId ?? throw new TenderException('project_manager_required');
        $references = $this->strategies->for($notice->jurisdiction)->billingReferences($notice, $participation);

        $start = isset($options['planned_start_date']) ? CarbonImmutable::parse($options['planned_start_date']) : $today;
        $end = isset($options['planned_end_date']) ? CarbonImmutable::parse($options['planned_end_date']) : $start->addYear();
        $value = Dec::money($participation->offered_amount ?? $notice->base_auction_amount);
        $title = (string) ($options['contract_title'] ?? $notice->title);
        $suffix = strtoupper(Str::random(6));

        return DB::transaction(function () use ($participation, $notice, $contactId, $managerId, $references, $start, $end, $value, $title, $suffix, $userId, $today): array {
            $tenantId = (string) $participation->tenant_id;

            $project = Project::create([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'contact_id' => $contactId,
                'project_number' => 'GARA-'.$today->format('Y').'-'.$suffix,
                'name' => $title,
                'description' => $notice->description,
                'project_type' => 'fixed_price',
                'status' => 'draft',
                'planned_start_date' => $start->toDateString(),
                'planned_end_date' => $end->toDateString(),
                'project_manager_user_id' => $managerId,
                'currency' => $notice->currency,
                'contract_value' => $value,
                'cup_code' => $references['cup'],
                'cig_code' => $references['cig'],
                'sdi_reference_block' => $references['sdi_reference_block'] ?? 'contract',
                'created_by' => $userId,
            ]);

            $contract = ServiceContract::create([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'customer_id' => $contactId,
                'contract_number' => 'CTR-GARA-'.$today->format('Y').'-'.$suffix,
                'title' => $title,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'annual_value' => $value,
                'currency' => $notice->currency,
                'status' => 'active',
                'auto_renew' => false,
                'terms' => ['contract_type' => 'tender_award', 'billing_cycle' => 'monthly'],
                'metadata' => [
                    'source' => 'tender_management',
                    'tender_id' => (string) $notice->id,
                    'participation_id' => (string) $participation->id,
                    'project_id' => (string) $project->id,
                    'jurisdiction' => $notice->jurisdiction->value,
                    'contracting_authority' => $notice->contracting_authority_name,
                    'references' => $references,
                ],
                'created_by' => $userId,
            ]);

            $participation->forceFill([
                'converted_contract_id' => $contract->id,
                'converted_project_job_id' => $project->id,
                'converted_at' => $today,
            ])->save();

            event(new TenderAwardConverted((string) $participation->id, (string) $contract->id, (string) $project->id, $tenantId));

            return ['participation' => $participation->refresh(), 'contract' => $contract, 'project' => $project, 'references' => $references];
        });
    }
}
