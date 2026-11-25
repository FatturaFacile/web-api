<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Modules\ElectronicSignature\Http\Requests\VerifySignatureRequest;
use Modules\ElectronicSignature\Services\SignatureVerificationService;
use OpenApi\Attributes as OA;

/**
 * Public verification of a signed document by its verification code, the SHA-256 of the file or the file itself.
 */
final class PublicVerificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SignatureVerificationService $verification) {}

    #[OA\Post(path: '/api/v1/public/signatures/verify', operationId: 'signaturesPublicVerify', summary: 'Verify a signed document by code, SHA-256 or uploaded file', tags: ['Electronic Signatures Portal'], responses: [new OA\Response(response: 200, description: 'Verification result')])]
    public function verify(VerifySignatureRequest $request): JsonResponse
    {
        $file = $request->file('file');

        $result = match (true) {
            $file instanceof UploadedFile => $this->verification->byFile((string) file_get_contents((string) $file->getRealPath())),
            $request->filled('sha256') => $this->verification->byHash((string) $request->validated('sha256')),
            default => $this->verification->byCode((string) $request->validated('code')),
        };

        return $this->successResponse($result, $result['found'] ? 'signatures.message.verified' : 'signatures.message.verifyNotFound');
    }
}
