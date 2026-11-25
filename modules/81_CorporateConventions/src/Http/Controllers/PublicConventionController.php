<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Modules\CorporateConventions\Application\Commands\RegisterConventionFamilyMemberCommand;
use Modules\CorporateConventions\Application\Commands\RegisterConventionFamilyMemberHandler;
use Modules\CorporateConventions\Application\Commands\RequestWorkEmailOtpCommand;
use Modules\CorporateConventions\Application\Commands\RequestWorkEmailOtpHandler;
use Modules\CorporateConventions\Application\Commands\VerifyBeneficiaryByCardOrHashCommand;
use Modules\CorporateConventions\Application\Commands\VerifyBeneficiaryByCardOrHashHandler;
use Modules\CorporateConventions\Application\Commands\VerifyBeneficiaryConventionByWorkEmailCommand;
use Modules\CorporateConventions\Application\Commands\VerifyBeneficiaryConventionByWorkEmailHandler;
use Modules\CorporateConventions\Domain\Enums\Relationship;
use Modules\CorporateConventions\Exceptions\ConventionException;
use Modules\CorporateConventions\Http\Requests\PublicConventionRequest;
use Modules\CorporateConventions\Http\Resources\PassResource;
use Modules\CorporateConventions\Http\Resources\PublicConventionResource;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Services\PublicAccessService;
use Modules\CorporateConventions\Services\WalletPassService;
use OpenApi\Attributes as OA;

/**
 * Self-service landing page of a convention (/convenzioni/<slug>): an employee verifies the eligibility with the work e-mail OTP or a
 * card / matricola, receives the digital pass and adds first-degree family members. No account: rate limited by IP, the company is
 * resolved from the slug of the convention, the pass from its unguessable id.
 */
final class PublicConventionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PublicAccessService $access, private readonly WalletPassService $wallets) {}

    #[OA\Get(path: '/api/v1/public/conventions/{slug}', operationId: 'publicConventionShow', summary: 'Public landing page data of a convention in force', tags: ['Corporate Conventions Public'], parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Branding and verification options'), new OA\Response(response: 404, description: 'Unknown or not in force')])]
    public function show(string $slug): JsonResponse
    {
        return $this->access->withConvention($slug, fn ($convention): JsonResponse => $this->successResponse(new PublicConventionResource($convention), $this->message('loaded')));
    }

    #[OA\Get(path: '/api/v1/public/conventions/{slug}/logo', operationId: 'publicConventionLogo', summary: 'Logo of the landing page of a convention', tags: ['Corporate Conventions Public'], parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Image')])]
    public function logo(string $slug): mixed
    {
        $path = $this->access->withConvention($slug, fn ($convention): ?string => $convention->logo_path);
        $disk = Storage::disk((string) config('corporate_conventions.storage.disk'));

        if ($path === null || ! $disk->exists($path)) {
            throw new ConventionException('convention_not_found', 404);
        }

        return $disk->response($path);
    }

    #[OA\Post(path: '/api/v1/public/conventions/{slug}/request-otp', operationId: 'publicConventionRequestOtp', summary: 'E-mail a one-time code to a work address of the partner', tags: ['Corporate Conventions Public'], parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 202, description: 'Code sent'), new OA\Response(response: 422, description: 'Domain not allowed')])]
    public function requestOtp(PublicConventionRequest $request, string $slug, RequestWorkEmailOtpHandler $handler): JsonResponse
    {
        return $this->access->withConvention($slug, function ($convention) use ($request, $handler): JsonResponse {
            $challenge = $handler->handle(new RequestWorkEmailOtpCommand($convention->id, (string) $request->validated('email'), app()->getLocale()));

            return $this->successResponse(['expires_at' => $challenge->expires_at->toIso8601String(), 'ttl_minutes' => (int) config('corporate_conventions.otp.ttl_minutes')], $this->message('otp_sent'), 202);
        });
    }

    #[OA\Post(path: '/api/v1/public/conventions/{slug}/verify-otp', operationId: 'publicConventionVerifyOtp', summary: 'Verify the one-time code and issue the convention pass', tags: ['Corporate Conventions Public'], parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 201, description: 'Pass issued')])]
    public function verifyOtp(PublicConventionRequest $request, string $slug, VerifyBeneficiaryConventionByWorkEmailHandler $handler): JsonResponse
    {
        return $this->access->withConvention($slug, function ($convention) use ($request, $handler): JsonResponse {
            $data = $request->validated();
            $enrollment = $handler->handle(new VerifyBeneficiaryConventionByWorkEmailCommand($convention->id, $data['email'], $data['code'], $data['full_name']));

            return $this->successResponse(new PassResource($enrollment->load(['convention', 'familyMembers'])), $this->message('enrolled'), 201);
        });
    }

    #[OA\Post(path: '/api/v1/public/conventions/{slug}/verify-identifier', operationId: 'publicConventionVerifyIdentifier', summary: 'Verify a membership card, matricola or promo code and issue the convention pass', tags: ['Corporate Conventions Public'], parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 201, description: 'Pass issued')])]
    public function verifyIdentifier(PublicConventionRequest $request, string $slug, VerifyBeneficiaryByCardOrHashHandler $handler): JsonResponse
    {
        return $this->access->withConvention($slug, function ($convention) use ($request, $handler): JsonResponse {
            $data = $request->validated();
            $enrollment = $handler->handle(new VerifyBeneficiaryByCardOrHashCommand($data['identifier'], $convention->id, $data['full_name'], $data['email'] ?? null))['enrollment'];

            return $this->successResponse(new PassResource($enrollment->load(['convention', 'familyMembers'])), $this->message('enrolled'), 201);
        });
    }

    #[OA\Get(path: '/api/v1/public/conventions/passes/{walletPassId}', operationId: 'publicConventionPass', summary: 'The convention pass of a beneficiary', tags: ['Corporate Conventions Public'], parameters: [new OA\Parameter(name: 'walletPassId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Pass with QR and wallet links')])]
    public function pass(string $walletPassId): JsonResponse
    {
        return $this->access->withPass($walletPassId, fn (ConventionBeneficiaryEnrollment $enrollment): JsonResponse => $this->successResponse(new PassResource($enrollment), $this->message('loaded')));
    }

    #[OA\Post(path: '/api/v1/public/conventions/passes/{walletPassId}/family-members', operationId: 'publicConventionAddFamilyMember', summary: 'Add a first-degree family member from the pass of the employee', tags: ['Corporate Conventions Public'], parameters: [new OA\Parameter(name: 'walletPassId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Family member enrolled')])]
    public function addFamilyMember(PublicConventionRequest $request, string $walletPassId, RegisterConventionFamilyMemberHandler $handler): JsonResponse
    {
        return $this->access->withPass($walletPassId, function (ConventionBeneficiaryEnrollment $parent) use ($request, $handler): JsonResponse {
            $handler->handle(new RegisterConventionFamilyMemberCommand($parent->id, (string) $request->validated('full_name'), Relationship::from((string) $request->validated('relationship'))));

            return $this->successResponse(new PassResource($parent->refresh()->load(['convention', 'familyMembers'])), $this->message('saved'), 201);
        });
    }

    #[OA\Get(path: '/api/v1/public/conventions/passes/{walletPassId}/qr.svg', operationId: 'publicConventionPassQrSvg', summary: 'QR code of the pass as SVG', tags: ['Corporate Conventions Public'], parameters: [new OA\Parameter(name: 'walletPassId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'SVG image')])]
    public function qrSvg(string $walletPassId): Response
    {
        return $this->access->withPass($walletPassId, fn (ConventionBeneficiaryEnrollment $enrollment): Response => response($this->wallets->qrSvg($enrollment->qr_payload), 200, ['Content-Type' => 'image/svg+xml']));
    }

    #[OA\Get(path: '/api/v1/public/conventions/passes/{walletPassId}/qr.png', operationId: 'publicConventionPassQrPng', summary: 'High resolution QR code of the pass as PNG', tags: ['Corporate Conventions Public'], parameters: [new OA\Parameter(name: 'walletPassId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PNG image')])]
    public function qrPng(string $walletPassId): Response
    {
        return $this->access->withPass($walletPassId, fn (ConventionBeneficiaryEnrollment $enrollment): Response => response($this->wallets->qrPng($enrollment->qr_payload), 200, ['Content-Type' => 'image/png']));
    }

    #[OA\Get(path: '/api/v1/public/conventions/passes/{walletPassId}/apple.pkpass', operationId: 'publicConventionPassApple', summary: 'Apple Wallet pass (.pkpass)', tags: ['Corporate Conventions Public'], parameters: [new OA\Parameter(name: 'walletPassId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'pkpass archive'), new OA\Response(response: 503, description: 'Apple Wallet not configured')])]
    public function applePass(string $walletPassId): Response
    {
        return $this->access->withPass($walletPassId, fn (ConventionBeneficiaryEnrollment $enrollment): Response => response($this->wallets->applePkpass($enrollment, $enrollment->convention), 200, [
            'Content-Type' => 'application/vnd.apple.pkpass',
            'Content-Disposition' => 'attachment; filename="convention-pass.pkpass"',
        ]));
    }

    private function message(string $key): string
    {
        return __('corporate_conventions.messages.'.$key);
    }
}
