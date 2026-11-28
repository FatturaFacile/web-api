<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;
use Modules\WithholdingRecipients\Exceptions\WithholdingRecipientException;
use Modules\WithholdingRecipients\Http\Controllers\Concerns\ResolvesWithholdingContext;
use Modules\WithholdingRecipients\Http\Requests\SaveRecipientRequest;
use Modules\WithholdingRecipients\Http\Resources\RecipientResource;
use Modules\WithholdingRecipients\Models\RecipientProfile;
use Modules\WithholdingRecipients\Models\RecipientSettlement;
use OpenApi\Attributes as OA;

/**
 * Registry of the recipients (payees) with their IT / CH tax identifiers and declarations.
 */
final class RecipientController extends Controller
{
    use ApiResponse;
    use ResolvesWithholdingContext;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/withholding-recipients/recipients', operationId: 'withholdingRecipientsList', summary: 'List the recipients', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], responses: [new OA\Response(response: 200, description: 'Recipients')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', RecipientProfile::class);

        return $this->successResponse(RecipientResource::collection(RecipientProfile::query()->orderBy('display_name')->get()));
    }

    #[OA\Post(path: '/api/v1/withholding-recipients/recipients', operationId: 'withholdingRecipientsCreate', summary: 'Register a recipient', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], responses: [new OA\Response(response: 201, description: 'Recipient created')])]
    public function store(SaveRecipientRequest $request): JsonResponse
    {
        $data = $request->validated();
        $regime = RecipientRegime::from((string) $data['regime']);

        $profile = new RecipientProfile($data + [
            'tenant_id' => (string) $this->tenantContext->getTenantId(),
            'jurisdiction' => $regime->jurisdiction()->value,
            'created_by' => $this->userId($request),
        ]);
        $profile->jurisdiction = $regime->jurisdiction()->value;
        $profile->save();

        return $this->successResponse(new RecipientResource($profile), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/withholding-recipients/recipients/{id}', operationId: 'withholdingRecipientsShow', summary: 'Show a recipient with its declarations and INL communications', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Recipient')])]
    public function show(string $id): JsonResponse
    {
        Gate::authorize('viewAny', RecipientProfile::class);

        return $this->successResponse(new RecipientResource(RecipientProfile::query()->with(['certificates' => fn ($q) => $q->orderByDesc('valid_to'), 'inlNotifications' => fn ($q) => $q->orderByDesc('work_start_date')])->findOrFail($id)));
    }

    #[OA\Put(path: '/api/v1/withholding-recipients/recipients/{id}', operationId: 'withholdingRecipientsUpdate', summary: 'Update a recipient', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Recipient updated')])]
    public function update(SaveRecipientRequest $request, string $id): JsonResponse
    {
        $profile = RecipientProfile::query()->findOrFail($id);
        $data = $request->validated();

        if (isset($data['regime']) && $data['regime'] !== $profile->regime && RecipientSettlement::query()->where('recipient_profile_id', $profile->id)->exists()) {
            throw new WithholdingRecipientException('The regime cannot change once compensations were posted.', 'regime_locked');
        }

        $profile->fill($data);

        if (isset($data['regime'])) {
            $profile->jurisdiction = RecipientRegime::from((string) $data['regime'])->jurisdiction()->value;
        }

        $profile->updated_by = $this->userId($request);
        $profile->save();

        return $this->successResponse(new RecipientResource($profile->refresh()), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/withholding-recipients/recipients/{id}', operationId: 'withholdingRecipientsDelete', summary: 'Delete a recipient without posted compensations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Recipient deleted')])]
    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('manage', RecipientProfile::class);
        $profile = RecipientProfile::query()->findOrFail($id);

        if (RecipientSettlement::query()->where('recipient_profile_id', $profile->id)->exists()) {
            throw new WithholdingRecipientException('A recipient with posted compensations cannot be deleted.', 'recipient_has_settlements');
        }

        $profile->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }
}
