<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Http\Requests\EnrollAutomationRequest;
use Modules\CRM\Http\Requests\SaveAutomationSequenceRequest;
use Modules\CRM\Models\AutomationEnrollment;
use Modules\CRM\Models\AutomationSequence;
use Modules\CRM\Models\AutomationStep;
use Modules\CRM\Models\AutomationStepLog;
use Modules\CRM\Services\AutomationService;
use OpenApi\Attributes as OA;

/**
 * Marketing automation sequences, enrolment and step log.
 */
final class AutomationSequenceController extends CrmExtensionController
{
    public function __construct(private readonly AutomationService $automation) {}

    #[OA\Get(path: '/api/v1/crm/automation-sequences', operationId: 'listCrmAutomationSequences', description: 'Marketing automation sequences with their steps.', summary: 'List automation sequences', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Automation'], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmAutomationSequence'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
    ])]
    public function index(): AnonymousResourceCollection
    {
        return $this->paginated($this->scoped(AutomationSequence::class)->with(['steps' => static fn ($query) => $query->orderBy('position')])->orderBy('name'));
    }

    #[OA\Post(path: '/api/v1/crm/automation-sequences', operationId: 'createCrmAutomationSequence', description: 'Creates a sequence with its ordered steps (email, task, wait; delay_days counts from the previous step). Triggers: manual, lead_created, lead_qualified, contact_created. Exit conditions: lead_converted, lead_disqualified, consent_withdrawn.', summary: 'Create an automation sequence', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmAutomationSequenceRequest')), tags: ['CRM Automation'], responses: [
        new OA\Response(response: 201, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmAutomationSequence')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function store(SaveAutomationSequenceRequest $request): JsonResponse
    {
        $this->authorize('create', AutomationSequence::class);
        $data = $request->validated();
        $sequence = DB::transaction(function () use ($data): AutomationSequence {
            $sequence = AutomationSequence::create(['tenant_id' => $this->tenantId(), ...Arr::except($data, ['steps']), 'exit_conditions' => $data['exit_conditions'] ?? []]);
            $this->replaceSteps($sequence, $data['steps'] ?? []);

            return $sequence;
        });

        return $this->present($sequence->load(['steps' => static fn ($query) => $query->orderBy('position')]), 201);
    }

    #[OA\Get(path: '/api/v1/crm/automation-sequences/{id}', operationId: 'getCrmAutomationSequence', description: 'Return a sequence with its steps.', summary: 'Show an automation sequence', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Automation'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmAutomationSequence')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        return $this->present($this->find(AutomationSequence::class, $id, 'view', ['steps' => static fn ($query) => $query->orderBy('position')]));
    }

    #[OA\Put(path: '/api/v1/crm/automation-sequences/{id}', operationId: 'updateCrmAutomationSequence', description: 'Changes the sequence; when steps are sent they replace the stored ones (enrolments pointing at a step that no longer exists complete on their next run).', summary: 'Update an automation sequence', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmAutomationSequenceRequest')), tags: ['CRM Automation'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmAutomationSequence')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function update(SaveAutomationSequenceRequest $request, string $id): JsonResponse
    {
        $sequence = $this->find(AutomationSequence::class, $id, 'update');
        $data = $request->validated();
        DB::transaction(function () use ($sequence, $data): void {
            $sequence->fill(Arr::except($data, ['steps']))->save();

            if (array_key_exists('steps', $data)) {
                $this->replaceSteps($sequence, $data['steps']);
            }
        });

        return $this->present($sequence->load(['steps' => static fn ($query) => $query->orderBy('position')]));
    }

    #[OA\Delete(path: '/api/v1/crm/automation-sequences/{id}', operationId: 'deleteCrmAutomationSequence', description: 'Delete a sequence without active enrolments.', summary: 'Delete an automation sequence', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Automation'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $sequence = $this->find(AutomationSequence::class, $id, 'delete');
        abort_if($sequence->enrollments()->where('status', 'active')->exists(), 422, 'The sequence has active enrolments.');

        return $this->deleteRecord($sequence);
    }

    #[OA\Post(path: '/api/v1/crm/automation-sequences/{id}/enroll', operationId: 'enrollCrmAutomationSequence', description: 'Enrols exactly one contact or lead (segment and entity of the sequence must match); the first step runs after its delay.', summary: 'Enrol a contact or lead', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmAutomationEnrollmentRequest')), tags: ['CRM Automation'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 201, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmAutomationEnrollment')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function enroll(EnrollAutomationRequest $request, string $id): JsonResponse
    {
        $sequence = $this->find(AutomationSequence::class, $id, 'update');
        $data = $request->validated();

        return $this->present($this->automation->enroll($sequence, $data['contact_id'] ?? null, $data['lead_id'] ?? null), 201);
    }

    #[OA\Get(path: '/api/v1/crm/automation-sequences/{id}/enrollments', operationId: 'listCrmAutomationEnrollments', description: 'The enrolments of the sequence, latest first. Filter: status.', summary: 'List enrolments of a sequence', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Automation'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmAutomationEnrollment'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function enrollments(Request $request, string $id): AnonymousResourceCollection
    {
        $this->find(AutomationSequence::class, $id);
        $status = $request->validate(['status' => ['nullable', 'string']])['status'] ?? null;

        return $this->paginated($this->scoped(AutomationEnrollment::class)->where('sequence_id', $id)->when($status !== null, static fn ($query) => $query->where('status', $status))->orderByDesc('enrolled_at'));
    }

    #[OA\Post(path: '/api/v1/crm/automation-sequences/run', operationId: 'runCrmAutomation', description: 'Executes the due step of every active enrolment of the company (the scheduler does the same); returns how many enrolments were handled.', summary: 'Run the due automation steps', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Automation'], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function run(): JsonResponse
    {
        $this->authorize('create', AutomationSequence::class);

        return response()->json(['data' => ['handled' => $this->automation->processDue($this->tenantId())]]);
    }

    #[OA\Post(path: '/api/v1/crm/automation-enrollments/{id}/exit', operationId: 'exitCrmAutomationEnrollment', description: 'Ends an active enrolment with the exit reason manual.', summary: 'Remove an enrolment from its sequence', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Automation'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmAutomationEnrollment')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function exitEnrollment(string $id): JsonResponse
    {
        return $this->present($this->automation->exit($this->find(AutomationEnrollment::class, $id, 'update'), 'manual'));
    }

    #[OA\Get(path: '/api/v1/crm/automation-enrollments/{id}/logs', operationId: 'listCrmAutomationStepLogs', description: 'Every executed step of the enrolment (sent, skipped, executed, waited, failed) with its message.', summary: 'Step execution log of an enrolment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Automation'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmAutomationStepLog'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function logs(string $id): AnonymousResourceCollection
    {
        $this->find(AutomationEnrollment::class, $id);

        return $this->paginated($this->scoped(AutomationStepLog::class)->where('enrollment_id', $id)->orderBy('executed_at')->orderBy('position'));
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function replaceSteps(AutomationSequence $sequence, array $steps): void
    {
        AutomationStep::query()->where('tenant_id', $sequence->tenant_id)->where('sequence_id', $sequence->id)->delete();

        foreach ($steps as $index => $step) {
            AutomationStep::create(['tenant_id' => $sequence->tenant_id, 'sequence_id' => $sequence->id, 'position' => $index + 1, ...$step]);
        }
    }
}
