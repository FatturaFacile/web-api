<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Associations\Http\Requests\MissionReportRequest;
use Modules\Associations\Http\Requests\PrepareFilingRequest;
use Modules\Associations\Http\Requests\ReasonRequest;
use Modules\Associations\Http\Requests\SaveStatementMappingRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\StatementFiling;
use Modules\Associations\Models\StatementMapping;
use Modules\Associations\OpenApi\AssociationDelete;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\OpenApi\AssociationPut;
use Modules\Associations\Services\ThirdSectorFilingService;
use Modules\Associations\Services\ThirdSectorStatementService;
use Modules\Associations\Support\AssociationCapability;
use Modules\Associations\Support\ThirdSectorScheme;

/**
 * Third-sector statements on the schemes of the decree of 5 March 2020, built from the ledger through the mapping of
 * the accounts, with their checks, the mission report and the preparation of the filing.
 */
final class StatementController extends AssociationController
{
    private const array FilingMeta = [
        'official_register_format' => false,
        'filing_made_by_authorized_person_on_register_portal' => true,
    ];

    public function __construct(
        private readonly ThirdSectorStatementService $statements,
        private readonly ThirdSectorFilingService $filings,
    ) {}

    #[AssociationGet('/api/v1/associations/statements/schemes', 'listAssociationStatementSchemes', 'The lines of the schemes, to map the accounts to', response: 'AssociationStatementSchemes')]
    public function schemes(): JsonResponse
    {
        $this->allow(AssociationCapability::Read);

        return response()->json(['data' => [
            'source' => ThirdSectorScheme::Source,
            'A' => ThirdSectorScheme::BalanceSheet,
            'B' => ThirdSectorScheme::ManagementReport,
            'D' => ThirdSectorScheme::CashReport,
            'C' => ThirdSectorScheme::MissionReportPoints,
        ]]);
    }

    #[AssociationGet('/api/v1/associations/statements/mappings', 'listAssociationStatementMappings', 'Which account feeds which line', query: ['scheme', 'per_page'], response: 'AssociationRecord')]
    public function mappings(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Read);

        return AssociationRecordResource::collection(StatementMapping::query()
            ->when($request->filled('scheme'), static fn ($query) => $query->where('scheme', $request->string('scheme')->toString()))
            ->orderBy('scheme')->orderBy('line_code')
            ->paginate($this->perPage($request, 100, 500)));
    }

    #[AssociationPost('/api/v1/associations/statements/mappings', 'createAssociationStatementMapping', 'Map an account to a line, optionally for one cost centre', 'AssociationStatementMappingRequest', response: 'AssociationRecord')]
    public function storeMapping(SaveStatementMappingRequest $request): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);
        $data = $request->validated();
        $exists = StatementMapping::query()->where('scheme', $data['scheme'])->where('account_id', $data['account_id'])
            ->where('cost_center_id', $data['cost_center_id'] ?? null)->exists();

        if ($exists) {
            return response()->json(['message' => __('association_finance.mapping_exists'), 'errors' => ['account_id' => [__('association_finance.mapping_exists')]]], 422);
        }

        return (new AssociationRecordResource(StatementMapping::create($data)))->response()->setStatusCode(201);
    }

    #[AssociationPut('/api/v1/associations/statements/mappings/{id}', 'updateAssociationStatementMapping', 'Change a mapping', 'AssociationStatementMappingRequest', response: 'AssociationRecord')]
    public function updateMapping(SaveStatementMappingRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Finance);
        $mapping = StatementMapping::query()->findOrFail($id);
        $mapping->update($request->validated());

        return new AssociationRecordResource($mapping);
    }

    #[AssociationDelete('/api/v1/associations/statements/mappings/{id}', 'deleteAssociationStatementMapping', 'Remove a mapping')]
    public function destroyMapping(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);
        StatementMapping::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    #[AssociationGet('/api/v1/associations/statements/{year}/management-report', 'showAssociationManagementReport', 'Management report (Mod. B) of the year with the previous year, optionally for some cost centres', query: ['cost_center_ids'], response: 'AssociationStatement')]
    public function managementReport(Request $request, string $year): JsonResponse
    {
        $this->allow(AssociationCapability::Read);

        return response()->json(['data' => $this->statements->managementReport((int) $year, $this->costCenters($request)), 'meta' => ['source' => ThirdSectorScheme::Source]]);
    }

    #[AssociationGet('/api/v1/associations/statements/{year}/balance-sheet', 'showAssociationBalanceSheet', 'Balance sheet (Mod. A) at the end of the year with the previous year', response: 'AssociationStatement')]
    public function balanceSheet(string $year): JsonResponse
    {
        $this->allow(AssociationCapability::Read);

        return response()->json(['data' => $this->statements->balanceSheet((int) $year), 'meta' => ['source' => ThirdSectorScheme::Source]]);
    }

    #[AssociationGet('/api/v1/associations/statements/{year}/cash-report', 'showAssociationCashReport', 'Cash report (Mod. D) of the year with the previous year', query: ['cost_center_ids'], response: 'AssociationStatement')]
    public function cashReport(Request $request, string $year): JsonResponse
    {
        $this->allow(AssociationCapability::Read);

        return response()->json(['data' => $this->statements->cashReport((int) $year, $this->costCenters($request)), 'meta' => ['source' => ThirdSectorScheme::Source]]);
    }

    #[AssociationGet('/api/v1/associations/statements/{year}/checks', 'checkAssociationStatements', 'Preventive checks for a form: mapping, balance, cash reconciliation, thresholds, secondary activities, commercial prevalence', query: ['form'], response: 'AssociationStatementChecks')]
    public function checks(Request $request, string $year): JsonResponse
    {
        $this->allow(AssociationCapability::Read);
        $form = in_array($request->string('form')->toString(), ThirdSectorFilingService::Forms, true) ? $request->string('form')->toString() : 'ordinary';

        return response()->json(['data' => $this->filings->checks((int) $year, $form)]);
    }

    #[AssociationGet('/api/v1/associations/statements/{year}/mission-report', 'showAssociationMissionReport', 'Outline of the mission report (Mod. C) with the facts already known and the texts written', response: 'AssociationMissionReport')]
    public function missionReport(string $year): JsonResponse
    {
        $this->allow(AssociationCapability::Read);

        return response()->json(['data' => $this->filings->missionOutline((int) $year), 'meta' => ['texts_written_by_the_body' => true]]);
    }

    #[AssociationPut('/api/v1/associations/statements/{year}/mission-report', 'saveAssociationMissionReport', 'Write the texts of the points of the mission report', 'AssociationMissionReportRequest', response: 'AssociationRecord')]
    public function saveMissionReport(MissionReportRequest $request, string $year): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Finance);

        return new AssociationRecordResource($this->filings->saveMissionAnswers((int) $year, $request->answers()));
    }

    #[AssociationGet('/api/v1/associations/statements/filings', 'listAssociationStatementFilings', 'Statements prepared and filed, year by year', query: ['per_page'], response: 'AssociationRecord')]
    public function filingsIndex(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Read);

        return AssociationRecordResource::collection(StatementFiling::query()->orderByDesc('year')->orderByDesc('created_at')->paginate($this->perPage($request)));
    }

    #[AssociationPost('/api/v1/associations/statements/{year}/filings', 'prepareAssociationStatementFiling', 'Freeze the statements of the year for the filing; blocking checks refuse it', 'AssociationPrepareFilingRequest', response: 'AssociationRecord')]
    public function prepare(PrepareFilingRequest $request, string $year): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);
        $filing = $this->filings->prepare((int) $year, (string) $request->validated('form'), $this->actorId($request));

        return (new AssociationRecordResource($filing))->additional(['meta' => self::FilingMeta])->response()->setStatusCode(201);
    }

    #[AssociationPost('/api/v1/associations/statement-filings/{id}/filed', 'markAssociationStatementFiled', 'Record the reference of the filing made on the register portal', 'AssociationRequiredReasonRequest', response: 'AssociationRecord')]
    public function markFiled(ReasonRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Finance);

        return new AssociationRecordResource($this->filings->markFiled(StatementFiling::query()->findOrFail($id), (string) $request->validated('reason'), $this->actorId($request)));
    }

    #[AssociationGet('/api/v1/associations/statement-filings/{id}/csv', 'exportAssociationStatementFilingCsv', 'A prepared statement as CSV', file: true)]
    public function csv(string $id): Response
    {
        $this->allow(AssociationCapability::Read);
        $filing = StatementFiling::query()->findOrFail($id);

        return response($this->filings->csv($filing), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"statements-{$filing->year}.csv\""]);
    }

    #[AssociationGet('/api/v1/associations/statement-filings/{id}/pdf', 'exportAssociationStatementFilingPdf', 'A prepared statement as PDF', file: true)]
    public function printable(string $id): Response
    {
        $this->allow(AssociationCapability::Read);
        $filing = StatementFiling::query()->findOrFail($id);

        return $this->pdf($this->filings->printable($filing), "statements-{$filing->year}.pdf");
    }

    /**
     * @return list<string>|null
     */
    private function costCenters(Request $request): ?array
    {
        if (! $request->filled('cost_center_ids')) {
            return null;
        }

        return array_values(array_filter(explode(',', $request->string('cost_center_ids')->toString()), static fn (string $id): bool => preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1));
    }
}
