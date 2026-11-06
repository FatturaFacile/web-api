<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Associations\Http\Requests\VerifyAccessRequest;
use Modules\Associations\Models\AccessDevice;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\OpenApi\OperationArguments;
use Modules\Associations\Services\AccessVerifier;

/**
 * The endpoint a QR or badge reader calls at every attempt.
 */
final class AccessVerifyController extends Controller
{
    public function __construct(private readonly AccessVerifier $verifier) {}

    #[AssociationPost('/api/v1/association-access/verify', 'verifyAssociationAccess', 'Check a QR code or a badge and answer allow or deny with a reason code', 'AssociationAccessVerifyRequest', OperationArguments::Device)]
    public function __invoke(VerifyAccessRequest $request): JsonResponse
    {
        /** @var AccessDevice $device */
        $device = $request->attributes->get('accessDevice');

        return response()->json(['data' => $this->verifier->verify($device, (string) $request->validated('credential'), $request->validated('direction'))]);
    }
}
