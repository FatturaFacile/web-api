<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Handlers;

use App\Models\Tenant;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Hse\Application\Commands\ReportWorkplaceIncidentCommand;
use Modules\Hse\Domain\Contracts\Mod074AccidentClaimBridgeInterface;
use Modules\Hse\Domain\Enums\IncidentStatus;
use Modules\Hse\Domain\Enums\Jurisdiction;
use Modules\Hse\Domain\Events\LostTimeWorkplaceAccidentReportedEvent;
use Modules\Hse\Domain\Exceptions\HseException;
use Modules\Hse\Infrastructure\Models\WorkplaceIncidentReport;
use Modules\HumanResources\Models\Employee;

/**
 * Logs a near-miss or an accident under the next protocol number of the year. A reportable accident (lost-time or fatal,
 * or an injury whose prognosis exceeds 1 day in Italy / 3 days in Switzerland) publishes the domain event and gets its
 * SUVA Sunet or INAIL draft in Mod 074. The draft is built before the incident is stored, so an accident without an
 * injured employee is refused instead of stored half-reported.
 */
final class ReportWorkplaceIncidentHandler
{
    public function __construct(
        private readonly Mod074AccidentClaimBridgeInterface $bridge,
        private readonly Dispatcher $events,
    ) {}

    public function handle(ReportWorkplaceIncidentCommand $command): WorkplaceIncidentReport
    {
        $jurisdiction = Jurisdiction::forCountry(Tenant::query()->findOrFail($command->tenantId)->country);
        $reportable = $command->incidentType->isReportable($jurisdiction, $command->prognosisDays);

        $people = array_values(array_unique([...$command->involvedEmployeeIds, ...$command->witnessEmployeeIds]));

        if ($people !== [] && Employee::query()->where('tenant_id', $command->tenantId)->whereIn('id', $people)->count() !== count($people)) {
            throw new HseException('The incident refers to an unknown employee.');
        }

        if ($reportable && $command->involvedEmployeeIds === []) {
            throw new HseException('A reportable accident needs the injured employee.');
        }

        $incident = $this->store($command, $jurisdiction, $reportable);

        if ($reportable) {
            $this->bridge->createDraft($incident);
            $this->events->dispatch(new LostTimeWorkplaceAccidentReportedEvent($command->tenantId, $incident->id, $incident->protocol_number, $jurisdiction->value, $command->prognosisDays));
        }

        return $incident->refresh();
    }

    private function store(ReportWorkplaceIncidentCommand $command, Jurisdiction $jurisdiction, bool $reportable): WorkplaceIncidentReport
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($command, $jurisdiction, $reportable): WorkplaceIncidentReport {
                    $year = $command->incidentDate->year;
                    $prefix = "INC-{$jurisdiction->value}-{$year}-";
                    $sequence = WorkplaceIncidentReport::withTrashed()->where('tenant_id', $command->tenantId)->where('protocol_number', 'like', $prefix.'%')->count() + 1;

                    return WorkplaceIncidentReport::create([
                        'tenant_id' => $command->tenantId,
                        'protocol_number' => $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                        'jurisdiction' => $jurisdiction,
                        // Stored in the application timezone: the column keeps no offset.
                        'incident_date' => $command->incidentDate->setTimezone((string) config('app.timezone')),
                        'reported_at' => now(),
                        'location_or_site_id' => $command->locationOrSiteId,
                        'work_center_id' => $command->workCenterId,
                        'incident_type' => $command->incidentType,
                        'involved_employee_ids_json' => $command->involvedEmployeeIds,
                        'witness_employee_ids_json' => $command->witnessEmployeeIds,
                        'description' => $command->description,
                        'immediate_actions_taken' => $command->immediateActionsTaken,
                        'root_cause_analysis_json' => $command->rootCauseAnalysis,
                        'corrective_actions_json' => $command->correctiveActions,
                        'prognosis_days' => $command->prognosisDays,
                        'injury_dynamics_json' => $command->injuryDynamics?->toArray(),
                        'status' => $reportable ? IncidentStatus::UnderInvestigation : IncidentStatus::Draft,
                    ]);
                });
            } catch (UniqueConstraintViolationException $violation) {
                // Two reports raced for the same protocol number: the next attempt counts again.
                if ($attempt >= 3) {
                    throw $violation;
                }
            }
        }
    }
}
