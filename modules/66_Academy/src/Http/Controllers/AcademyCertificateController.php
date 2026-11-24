<?php

declare(strict_types=1);

namespace Modules\Academy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Modules\Academy\Commands\IssueAcademyCertificatePdfCommand;
use Modules\Academy\Domain\Enums\AcademyLocale;
use Modules\Academy\Http\Requests\AcademyLocaleRequest;
use Modules\Academy\Http\Resources\AcademyArrayResource;
use Modules\Academy\Models\AcademyCertificate;
use Modules\Academy\Queries\VerifyAcademyCertificateQuery;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The certificate PDF of the caller and the public verification of any certificate.
 */
final class AcademyCertificateController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(
        path: '/api/v1/academy/tracks/{trackId}/certificate',
        operationId: 'downloadAcademyCertificate',
        description: 'The PDF certificate of a completed track, with a QR code pointing to the public verification endpoint.',
        summary: 'Download the track certificate (PDF)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Academy'],
        parameters: [new OA\Parameter(name: 'trackId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'locale', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'PDF certificate', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 404, description: 'The track is not completed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function download(AcademyLocaleRequest $request, string $trackId, IssueAcademyCertificatePdfCommand $command): HttpResponse
    {
        $certificate = AcademyCertificate::query()
            ->where('tenant_id', (string) $this->tenantContext->getTenantId())
            ->where('user_id', (string) $request->user()?->id)
            ->where('track_id', $trackId)
            ->firstOrFail();

        return response($command->execute($certificate, $request->academyLocale()), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="academy-certificate-'.$certificate->certificate_code.'.pdf"',
        ]);
    }

    #[OA\Get(
        path: '/api/v1/academy/certificates/verify/{certificateCode}',
        operationId: 'verifyAcademyCertificate',
        description: 'Public, no authentication: confirms that a certificate exists and shows what is printed on it.',
        summary: 'Verify a certificate',
        tags: ['Academy'],
        parameters: [new OA\Parameter(name: 'certificateCode', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'locale', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Valid certificate', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AcademyCertificateVerification')])),
            new OA\Response(response: 404, description: 'Unknown certificate', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function verify(AcademyLocaleRequest $request, string $certificateCode, VerifyAcademyCertificateQuery $query): JsonResponse
    {
        $result = $query->execute($certificateCode, AcademyLocale::fromInput($request->validated('locale')));

        if ($result === null) {
            return $this->problemDetailsResponse('Not found', 'Certificate not found.', Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(new AcademyArrayResource($result));
    }
}
