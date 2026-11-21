<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Hse\Application\Commands\AssignPpeAndCaptureSignatureCommand;
use Modules\Hse\Application\Commands\RecordMedicalFitnessOutcomeCommand;
use Modules\Hse\Application\Commands\RecordToolBoxTalkAttendanceCommand;
use Modules\Hse\Application\Commands\RegisterDvrRevisionCommand;
use Modules\Hse\Application\Commands\RegisterSafetyTrainingCompletionCommand;
use Modules\Hse\Application\Commands\ReportWorkplaceIncidentCommand;
use Modules\Hse\Application\Commands\SaveRequirementMatrixCommand;
use Modules\Hse\Application\Commands\SaveSafetyConceptAuditCommand;
use Modules\Hse\Application\Handlers\AssignPpeAndCaptureSignatureHandler;
use Modules\Hse\Application\Handlers\GetExpiringSafetyCertificatesAndMedicalVisitsHandler;
use Modules\Hse\Application\Handlers\GetHseComplianceMatrixHandler;
use Modules\Hse\Application\Handlers\RecordMedicalFitnessOutcomeHandler;
use Modules\Hse\Application\Handlers\RecordToolBoxTalkAttendanceHandler;
use Modules\Hse\Application\Handlers\RegisterDvrRevisionHandler;
use Modules\Hse\Application\Handlers\RegisterSafetyTrainingCompletionHandler;
use Modules\Hse\Application\Handlers\ReportWorkplaceIncidentHandler;
use Modules\Hse\Application\Handlers\SaveRequirementMatrixHandler;
use Modules\Hse\Application\Handlers\SaveSafetyConceptAuditHandler;
use Modules\Hse\Application\Handlers\ValidateEmployeeHseClearanceForDispatchHandler;
use Modules\Hse\Application\Queries\GetExpiringSafetyCertificatesAndMedicalVisitsQuery;
use Modules\Hse\Application\Queries\GetHseComplianceMatrixQuery;
use Modules\Hse\Application\Queries\ValidateEmployeeHseClearanceForDispatchQuery;
use Modules\Hse\Domain\Enums\CertificateType;
use Modules\Hse\Domain\Enums\ClearanceContext;
use Modules\Hse\Domain\Enums\FitnessOutcome;
use Modules\Hse\Domain\Enums\IncidentType;
use Modules\Hse\Domain\Enums\Jurisdiction;
use Modules\Hse\Domain\Enums\TrafficLight;
use Modules\Hse\Domain\Enums\VisitType;
use Modules\Hse\Domain\Exceptions\HseAccessDeniedException;
use Modules\Hse\Domain\Exceptions\HseException;
use Modules\Hse\Domain\Exceptions\HseNotFoundException;
use Modules\Hse\Domain\ValueObjects\InjuryDynamics;
use Modules\Hse\Infrastructure\Models\AccidentClaimDraft;
use Modules\Hse\Infrastructure\Models\DvrDocumentRegistry;
use Modules\Hse\Infrastructure\Models\EmployeeSafetyCertificate;
use Modules\Hse\Infrastructure\Models\IncidentPhoto;
use Modules\Hse\Infrastructure\Models\MedicalClinicalVaultEntry;
use Modules\Hse\Infrastructure\Models\OccupationalMedicalVisit;
use Modules\Hse\Infrastructure\Models\PpeDistributionRecord;
use Modules\Hse\Infrastructure\Models\SafetyConceptAudit;
use Modules\Hse\Infrastructure\Models\SafetyRoleRequirementMatrix;
use Modules\Hse\Infrastructure\Models\WorkplaceIncidentReport;
use Modules\Hse\Presentation\Http\Requests\AssignPpeRequest;
use Modules\Hse\Presentation\Http\Requests\ClearanceCheckRequest;
use Modules\Hse\Presentation\Http\Requests\ComplianceMatrixRequest;
use Modules\Hse\Presentation\Http\Requests\RecordMedicalFitnessRequest;
use Modules\Hse\Presentation\Http\Requests\RegisterDvrRevisionRequest;
use Modules\Hse\Presentation\Http\Requests\RegisterTrainingRequest;
use Modules\Hse\Presentation\Http\Requests\ReportIncidentRequest;
use Modules\Hse\Presentation\Http\Requests\SaveRequirementMatrixRequest;
use Modules\Hse\Presentation\Http\Requests\SaveSafetyAuditRequest;
use Modules\Hse\Presentation\Http\Requests\ToolBoxTalkRequest;
use Modules\Hse\Presentation\Http\Resources\AccidentClaimDraftResource;
use Modules\Hse\Presentation\Http\Resources\IncidentReportResource;
use Modules\Hse\Presentation\Http\Resources\MedicalVisitResource;
use Modules\Hse\Presentation\Http\Resources\PpeDistributionResource;
use Modules\Hse\Presentation\Http\Resources\RequirementMatrixResource;
use Modules\Hse\Presentation\Http\Resources\SafetyCertificateResource;
use Modules\HumanResources\Models\EmployeeDocument;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class HseController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext, private readonly ModuleAccessGate $gate) {}

    #[OA\Get(path: '/api/v1/hse/compliance-matrix', operationId: 'getHseComplianceMatrix', summary: 'Traffic-light compliance matrix', description: 'Employees by mandatory trainings, medical fitness and PPE with GREEN / YELLOW / RED lights, paginated and filterable, with the KPI cards.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 200, description: 'The matrix')])]
    public function complianceMatrix(ComplianceMatrixRequest $request, GetHseComplianceMatrixHandler $handler): JsonResponse
    {
        $result = $handler->handle(new GetHseComplianceMatrixQuery(
            $this->tenantId(), $request->filled('as_of') ? CarbonImmutable::parse((string) $request->input('as_of')) : CarbonImmutable::today(),
            (int) $request->input('page', 1), (int) $request->input('per_page', 25),
            $request->filled('department_id') ? (string) $request->input('department_id') : null,
            $request->filled('work_center_id') ? (string) $request->input('work_center_id') : null,
            $request->filled('construction_site_id') ? (string) $request->input('construction_site_id') : null,
            $request->filled('status') ? TrafficLight::from((string) $request->input('status')) : null,
            $request->filled('jurisdiction') ? Jurisdiction::from((string) $request->input('jurisdiction')) : null,
        ));

        return $this->successResponse($result['data'], meta: [...$result['meta'], 'kpis' => $result['kpis']]);
    }

    #[OA\Get(path: '/api/v1/hse/clearance', operationId: 'validateEmployeeHseClearance', summary: 'HSE hard-block check for a dispatch', description: 'Called by MES dispatch (Mod 064), field service (Mod 084) and construction sites (Mod 108): is_cleared is false with the blocking reasons when a required certificate, medical judgment or PPE is expired, missing or unfit.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 200, description: 'The verdict')])]
    public function clearance(ClearanceCheckRequest $request, ValidateEmployeeHseClearanceForDispatchHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new ValidateEmployeeHseClearanceForDispatchQuery(
            $this->tenantId(), (string) $request->input('employee_id'), ClearanceContext::from((string) $request->input('target_context_type')),
            (string) $request->input('target_id'), CarbonImmutable::parse((string) $request->input('scheduled_date_time')),
        ))->toArray()));
    }

    #[OA\Get(path: '/api/v1/hse/expirations', operationId: 'getHseExpirations', summary: 'Certificates, medical visits and PPE expiring', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 200, description: 'The deadlines')])]
    public function expirations(Request $request, GetExpiringSafetyCertificatesAndMedicalVisitsHandler $handler): JsonResponse
    {
        return $this->successResponse($handler->handle(new GetExpiringSafetyCertificatesAndMedicalVisitsQuery(
            $this->tenantId(), CarbonImmutable::today(), min(365, max(1, (int) $request->query('within_days', 60))),
        )));
    }

    #[OA\Get(path: '/api/v1/hse/requirement-matrices', operationId: 'listHseRequirementMatrices', summary: 'Role and work center requirement rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 200, description: 'The rules')])]
    public function matrices(): JsonResponse
    {
        return $this->successResponse(RequirementMatrixResource::collection(SafetyRoleRequirementMatrix::query()->where('tenant_id', $this->tenantId())->orderBy('name')->get()));
    }

    #[OA\Post(path: '/api/v1/hse/requirement-matrices', operationId: 'saveHseRequirementMatrix', summary: 'Create or update a requirement rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 201, description: 'The rule')])]
    #[OA\Put(path: '/api/v1/hse/requirement-matrices/{id}', operationId: 'updateHseRequirementMatrix', summary: 'Update a requirement rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The rule')])]
    public function saveMatrix(SaveRequirementMatrixRequest $request, SaveRequirementMatrixHandler $handler, ?string $id = null): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new RequirementMatrixResource($handler->handle(new SaveRequirementMatrixCommand(
            $this->tenantId(), (string) $request->input('name'), Jurisdiction::from((string) $request->input('jurisdiction')),
            $request->input('job_position_id'), $request->input('work_center_id'), $request->input('construction_site_id'),
            $request->input('suva_hazard_class'), $request->input('dvr_risk_profile_code'),
            array_values((array) $request->input('required_training_types', [])), array_values((array) $request->input('required_medical_exam_codes', [])),
            array_values((array) $request->input('required_ppe_product_ids', [])), (bool) $request->input('is_active', true), $id,
            $request->filled('expected_version') ? (int) $request->input('expected_version') : null,
        ))), statusCode: $id === null ? Response::HTTP_CREATED : Response::HTTP_OK));
    }

    #[OA\Get(path: '/api/v1/hse/certificates', operationId: 'listHseCertificates', summary: 'Safety certificates of an employee', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 200, description: 'The certificates')])]
    public function certificates(Request $request): JsonResponse
    {
        return $this->successResponse(SafetyCertificateResource::collection(EmployeeSafetyCertificate::query()->where('tenant_id', $this->tenantId())
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', (string) $request->query('employee_id')))->orderByDesc('issued_at')->get()));
    }

    #[OA\Post(path: '/api/v1/hse/certificates', operationId: 'registerHseTrainingCompletion', summary: 'Register a completed training or license', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 201, description: 'The certificate')])]
    public function registerTraining(RegisterTrainingRequest $request, RegisterSafetyTrainingCompletionHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new SafetyCertificateResource($handler->handle(new RegisterSafetyTrainingCompletionCommand(
            $this->tenantId(), (string) $request->input('employee_id'), CertificateType::from((string) $request->input('certificate_type')),
            $request->input('certificate_number'), $request->input('training_provider_name'), (float) $request->input('hours_completed', 0),
            CarbonImmutable::parse((string) $request->input('issued_at')), $request->filled('expires_at') ? CarbonImmutable::parse((string) $request->input('expires_at')) : null,
            (bool) $request->input('specific_risk_class', false), $request->input('certificate_pdf_base64'), $this->user()?->id,
        ))), statusCode: Response::HTTP_CREATED));
    }

    #[OA\Get(path: '/api/v1/hse/medical-visits', operationId: 'listHseMedicalVisits', summary: 'Physician judgments (fitness and prescriptions only)', description: 'Shows the judgment and its operational prescriptions. Clinical notes are never part of the response.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 200, description: 'The judgments')])]
    public function medicalVisits(Request $request): JsonResponse
    {
        return $this->successResponse(MedicalVisitResource::collection(OccupationalMedicalVisit::query()->where('tenant_id', $this->tenantId())
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', (string) $request->query('employee_id')))->orderByDesc('visit_date')->get()));
    }

    #[OA\Post(path: '/api/v1/hse/medical-visits', operationId: 'recordHseMedicalFitness', summary: 'Record the physician judgment', description: 'Clinical notes are stored only when the caller holds the hse_clinical write grant; for anybody else the field is refused.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 201, description: 'The judgment')])]
    public function recordMedical(RecordMedicalFitnessRequest $request, RecordMedicalFitnessOutcomeHandler $handler): JsonResponse
    {
        $notes = $request->filled('clinical_notes') ? (string) $request->input('clinical_notes') : null;

        if ($notes !== null && ! $this->canAccessClinical('write')) {
            return $this->problemDetailsResponse('Forbidden Clinical Data', 'Only the occupational physician may record clinical notes.', Response::HTTP_FORBIDDEN);
        }

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new MedicalVisitResource($handler->handle(new RecordMedicalFitnessOutcomeCommand(
            $this->tenantId(), (string) $request->input('employee_id'), (string) $request->input('doctor_name'), $request->input('doctor_registration_number'),
            VisitType::from((string) $request->input('visit_type')), CarbonImmutable::parse((string) $request->input('visit_date')),
            $request->filled('next_visit_due_date') ? CarbonImmutable::parse((string) $request->input('next_visit_due_date')) : null,
            FitnessOutcome::from((string) $request->input('fitness_outcome')), $request->input('restrictions_summary'), $notes,
            $request->input('certificate_document_id'), $this->user()?->id,
        ))), statusCode: Response::HTTP_CREATED));
    }

    #[OA\Get(path: '/api/v1/hse/medical-visits/{id}/clinical-notes', operationId: 'getHseClinicalNotes', summary: 'Clinical notes of a visit (physician only)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The notes'), new OA\Response(response: 403, description: 'Not the physician')])]
    public function clinicalNotes(string $id): JsonResponse
    {
        return $this->guarded(function () use ($id): JsonResponse {
            $this->canAccessClinical('read') || throw new HseAccessDeniedException('Only the occupational physician may read clinical notes.');
            OccupationalMedicalVisit::query()->where('tenant_id', $this->tenantId())->whereKey($id)->exists() || throw new HseNotFoundException('Unknown visit.');
            $entry = MedicalClinicalVaultEntry::query()->where('tenant_id', $this->tenantId())->where('medical_visit_id', $id)->first() ?? throw new HseNotFoundException('No clinical notes for this visit.');

            return $this->successResponse(['medical_visit_id' => $id, 'clinical_notes' => $entry->encrypted_notes]);
        });
    }

    #[OA\Get(path: '/api/v1/hse/ppe-distributions', operationId: 'listHsePpeDistributions', summary: 'PPE hand-over archive', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 200, description: 'The hand-overs')])]
    public function ppeDistributions(Request $request): JsonResponse
    {
        return $this->successResponse(PpeDistributionResource::collection(PpeDistributionRecord::query()->where('tenant_id', $this->tenantId())
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', (string) $request->query('employee_id')))->orderByDesc('handed_over_at')->limit(500)->get()));
    }

    #[OA\Post(path: '/api/v1/hse/ppe-distributions', operationId: 'assignHsePpeAndCaptureSignature', summary: 'Hand PPE to a worker with signature', description: 'Checks and decrements the warehouse stock, hashes the signature and files the Verbale di Consegna PDF in the employee dossier.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 201, description: 'The hand-over'), new OA\Response(response: 422, description: 'Not enough stock')])]
    public function assignPpe(AssignPpeRequest $request, AssignPpeAndCaptureSignatureHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new PpeDistributionResource($handler->handle(new AssignPpeAndCaptureSignatureCommand(
            $this->tenantId(), (string) $request->input('employee_id'), (string) $request->input('product_id'), (string) $request->input('warehouse_id'),
            $request->input('lot_or_serial_id'), (float) $request->input('quantity'), $request->input('ce_marking_standard'),
            $request->filled('handed_over_at') ? CarbonImmutable::parse((string) $request->input('handed_over_at')) : CarbonImmutable::now(),
            $request->filled('replacement_due_at') ? CarbonImmutable::parse((string) $request->input('replacement_due_at')) : null,
            (string) $request->input('signature_payload'), $request->input('signature_image'), (string) ($request->input('locale') ?: 'en'), $this->user()?->id,
        ))), statusCode: Response::HTTP_CREATED));
    }

    #[OA\Get(path: '/api/v1/hse/ppe-distributions/{id}/verbale', operationId: 'downloadHseVerbaleConsegna', summary: 'Download the signed hand-over PDF', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The PDF')])]
    public function verbale(string $id): StreamedResponse|JsonResponse
    {
        $record = PpeDistributionRecord::query()->where('tenant_id', $this->tenantId())->whereKey($id)->first();
        $document = $record?->verbale_consegna_pdf_id === null ? null : EmployeeDocument::query()->where('tenant_id', $this->tenantId())->whereKey($record->verbale_consegna_pdf_id)->first();

        if ($document === null) {
            return $this->problemDetailsResponse('Not Found', 'No signed hand-over record for this PPE.', Response::HTTP_NOT_FOUND);
        }

        return Storage::disk((string) config('filesystems.private_disk', config('filesystems.default', 'local')))->download($document->file_path, 'verbale-consegna-'.$record->id.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    #[OA\Get(path: '/api/v1/hse/incidents', operationId: 'listHseIncidents', summary: 'Incident and near-miss registry', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 200, description: 'The incidents')])]
    public function incidents(): JsonResponse
    {
        return $this->successResponse(IncidentReportResource::collection(WorkplaceIncidentReport::query()->with('photos:id,incident_report_id')->where('tenant_id', $this->tenantId())->orderByDesc('incident_date')->limit(500)->get()));
    }

    #[OA\Post(path: '/api/v1/hse/incidents', operationId: 'reportHseIncident', summary: 'Report a near-miss or an accident', description: 'A reportable accident also creates the SUVA Sunet (CH) or INAIL (IT) claim draft in Mod 074.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 201, description: 'The incident')])]
    public function reportIncident(ReportIncidentRequest $request, ReportWorkplaceIncidentHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new IncidentReportResource($handler->handle(new ReportWorkplaceIncidentCommand(
            $this->tenantId(), IncidentType::from((string) $request->input('incident_type')), CarbonImmutable::parse((string) $request->input('incident_date')),
            $request->input('location_or_site_id'), $request->input('work_center_id'),
            array_values((array) $request->input('involved_employee_ids', [])), array_values((array) $request->input('witness_employee_ids', [])),
            (string) $request->input('description'), $request->input('immediate_actions_taken'), (int) $request->input('prognosis_days', 0),
            InjuryDynamics::fromArray($request->input('injury_dynamics')), $request->input('root_cause_analysis'),
            $request->input('corrective_actions') === null ? null : array_values((array) $request->input('corrective_actions')), $this->user()?->id,
            array_values(array_map('strval', (array) $request->input('photos', []))),
        ))), statusCode: Response::HTTP_CREATED));
    }

    #[OA\Get(path: '/api/v1/hse/incidents/{id}/photos/{photoId}', operationId: 'downloadHseIncidentPhoto', summary: 'A photo attached to an incident', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'photoId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The image')])]
    public function incidentPhoto(string $id, string $photoId): StreamedResponse
    {
        $photo = IncidentPhoto::query()->where('tenant_id', $this->tenantId())->where('incident_report_id', $id)->whereKey($photoId)->firstOrFail();

        return Storage::disk((string) config('filesystems.private_disk', config('filesystems.default', 'local')))->response($photo->file_path, null, ['Content-Type' => $photo->mime_type]);
    }

    #[OA\Get(path: '/api/v1/hse/incidents/{id}/claim-draft', operationId: 'getHseIncidentClaimDraft', summary: 'SUVA / INAIL claim draft of an incident', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The draft')])]
    public function claimDraft(string $id): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new AccidentClaimDraftResource(
            AccidentClaimDraft::query()->where('tenant_id', $this->tenantId())->where('incident_report_id', $id)->first() ?? throw new HseNotFoundException('No claim draft for this incident.'),
        )));
    }

    #[OA\Post(path: '/api/v1/hse/toolbox-talks', operationId: 'recordHseToolBoxTalk', summary: 'Record a tool-box talk with signatures', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 201, description: 'The briefing')])]
    public function toolBoxTalk(ToolBoxTalkRequest $request, RecordToolBoxTalkAttendanceHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($request, $handler): JsonResponse {
            $talk = $handler->handle(new RecordToolBoxTalkAttendanceCommand(
                $this->tenantId(), (string) $request->input('topic'), $request->input('site_or_work_center'),
                array_values((array) $request->input('checklist_points', [])), $request->input('supervisor_employee_id'),
                CarbonImmutable::parse((string) $request->input('conducted_at')),
                array_map(static fn (array $row): array => [
                    'employee_id' => (string) $row['employee_id'], 'signature_payload' => (string) $row['signature_payload'],
                    'signature_image' => $row['signature_image'] ?? null, 'signed_at' => CarbonImmutable::parse((string) $row['signed_at']),
                ], array_values((array) $request->input('attendances'))),
                $this->user()?->id, $request->filled('talk_id') ? (string) $request->input('talk_id') : null,
            ));

            return $this->successResponse(['id' => $talk->id, 'topic' => $talk->topic, 'attendees' => $talk->attendances->count()], statusCode: Response::HTTP_CREATED);
        });
    }

    #[OA\Get(path: '/api/v1/hse/audits', operationId: 'listHseAudits', summary: 'MSSL/ASA audits and DVR revisions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 200, description: 'The audits and revisions')])]
    public function audits(): JsonResponse
    {
        return $this->successResponse([
            'mssl_audits' => SafetyConceptAudit::query()->where('tenant_id', $this->tenantId())->orderByDesc('audit_date')->get()->map(fn (SafetyConceptAudit $audit): array => [
                'id' => $audit->id, 'audit_date' => $audit->audit_date->toDateString(), 'score_percent' => $audit->score_percent, 'status' => $audit->status,
                'answers' => $audit->answers_json, 'corrective_actions' => $audit->corrective_actions_json ?? [],
            ])->all(),
            'dvr_revisions' => DvrDocumentRegistry::query()->where('tenant_id', $this->tenantId())->orderByDesc('revision_number')->get()->map(fn (DvrDocumentRegistry $dvr): array => [
                'id' => $dvr->id, 'revision_number' => $dvr->revision_number, 'issued_at' => $dvr->issued_at->toDateString(), 'review_due_at' => $dvr->review_due_at?->toDateString(),
                'signatures' => $dvr->signatures_json ?? [], 'status' => $dvr->status,
            ])->all(),
        ]);
    }

    #[OA\Post(path: '/api/v1/hse/audits/mssl', operationId: 'saveHseMsslAudit', summary: 'Save a 10-point MSSL/ASA audit', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 201, description: 'The audit')])]
    public function saveAudit(SaveSafetyAuditRequest $request, SaveSafetyConceptAuditHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($request, $handler): JsonResponse {
            $audit = $handler->handle(new SaveSafetyConceptAuditCommand(
                $this->tenantId(), CarbonImmutable::parse((string) $request->input('audit_date')),
                array_map('strval', (array) $request->input('answers')), array_values((array) $request->input('corrective_actions', [])),
            ));

            return $this->successResponse(['id' => $audit->id, 'score_percent' => $audit->score_percent, 'status' => $audit->status], statusCode: Response::HTTP_CREATED);
        });
    }

    #[OA\Post(path: '/api/v1/hse/audits/dvr', operationId: 'registerHseDvrRevision', summary: 'Register a DVR revision', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['HSE'], responses: [new OA\Response(response: 201, description: 'The revision')])]
    public function registerDvr(RegisterDvrRevisionRequest $request, RegisterDvrRevisionHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($request, $handler): JsonResponse {
            $dvr = $handler->handle(new RegisterDvrRevisionCommand(
                $this->tenantId(), CarbonImmutable::parse((string) $request->input('issued_at')),
                $request->filled('review_due_at') ? CarbonImmutable::parse((string) $request->input('review_due_at')) : null,
                (array) $request->input('signatures', []), $request->input('document_id'),
            ));

            return $this->successResponse(['id' => $dvr->id, 'revision_number' => $dvr->revision_number, 'status' => $dvr->status], statusCode: Response::HTTP_CREATED);
        });
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function canAccessClinical(string $access): bool
    {
        return $this->gate->allows($this->user(), $this->tenantContext->getTenantId(), 'hse_clinical', $access);
    }

    /**
     * Maps the module exceptions to problem details.
     *
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T|JsonResponse
     */
    private function guarded(Closure $action): mixed
    {
        try {
            return $action();
        } catch (HseNotFoundException $exception) {
            return $this->problemDetailsResponse('Not Found', $exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (HseAccessDeniedException $exception) {
            return $this->problemDetailsResponse('Forbidden', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (HseException $exception) {
            return $this->problemDetailsResponse('HSE Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
