<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\WithholdingRecipients\Exceptions\WithholdingRecipientException;
use Modules\WithholdingRecipients\Http\Controllers\Concerns\ResolvesWithholdingContext;
use Modules\WithholdingRecipients\Http\Requests\SaveCertificateRequest;
use Modules\WithholdingRecipients\Http\Resources\CertificateResource;
use Modules\WithholdingRecipients\Models\AgentReducedWithholdingCert;
use Modules\WithholdingRecipients\Models\RecipientProfile;
use OpenApi\Attributes as OA;

/**
 * Annual declarations of the agents (continuous use of employees or third parties) that lower the withholding base to 20%.
 */
final class CertificateController extends Controller
{
    use ApiResponse;
    use ResolvesWithholdingContext;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/withholding-recipients/recipients/{id}/certificates', operationId: 'withholdingRecipientsCertificates', summary: 'Reduced-withholding declarations of an agent', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Declarations')])]
    public function index(string $id): JsonResponse
    {
        Gate::authorize('viewAny', RecipientProfile::class);
        $profile = RecipientProfile::query()->findOrFail($id);

        return $this->successResponse(CertificateResource::collection($profile->certificates()->orderByDesc('valid_to')->get()));
    }

    #[OA\Post(path: '/api/v1/withholding-recipients/recipients/{id}/certificates', operationId: 'withholdingRecipientsCertificateStore', summary: 'Record the annual 20% declaration of an agent', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Declaration recorded')])]
    public function store(SaveCertificateRequest $request, string $id): JsonResponse
    {
        $profile = RecipientProfile::query()->findOrFail($id);

        if ($profile->regime !== 'agent_25bis') {
            throw new WithholdingRecipientException('Only agents can declare the reduced withholding.', 'certificate_not_applicable');
        }

        $certificate = new AgentReducedWithholdingCert($request->validated() + [
            'tenant_id' => (string) $this->tenantContext->getTenantId(),
            'recipient_profile_id' => $profile->id,
            'created_by' => $this->userId($request),
        ]);
        $certificate->save();

        return $this->successResponse(new CertificateResource($certificate), $this->message('saved'), 201);
    }

    #[OA\Delete(path: '/api/v1/withholding-recipients/certificates/{id}', operationId: 'withholdingRecipientsCertificateDelete', summary: 'Delete a declaration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Declaration deleted')])]
    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('manage', AgentReducedWithholdingCert::class);
        AgentReducedWithholdingCert::query()->findOrFail($id)->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }
}
