<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Modules\ElectronicSignature\Enums\AuditEvent;
use Modules\ElectronicSignature\Enums\EnvelopeStatus;
use Modules\ElectronicSignature\Enums\ProviderType;
use Modules\ElectronicSignature\Enums\RecipientStatus;
use Modules\ElectronicSignature\Enums\SignatureLevel;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Http\Requests\DeclineSignatureRequest;
use Modules\ElectronicSignature\Http\Requests\SignDocumentRequest;
use Modules\ElectronicSignature\Services\EnvelopeStorage;
use Modules\ElectronicSignature\Services\SignatureAuditService;
use Modules\ElectronicSignature\Services\SigningService;
use OpenApi\Attributes as OA;

/**
 * The public signing portal. No account: the unguessable token of the link is the credential, and every refusal of an
 * unknown link is the same 404. Everything is throttled by IP address (see the routes).
 */
final class PublicSigningController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SigningService $signing,
        private readonly EnvelopeStorage $storage,
        private readonly SignatureAuditService $audit,
    ) {}

    #[OA\Get(path: '/api/v1/public/sign/{token}', operationId: 'signaturesPublicShow', summary: 'The signing page of a recipient: envelope, what is asked, consent keys', tags: ['Electronic Signatures Portal'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Signing context')])]
    public function show(Request $request, string $token): JsonResponse
    {
        $recipient = $this->signing->resolveToken($token);
        $this->signing->markViewed($recipient, $request->ip(), $request->userAgent());
        $recipient->refresh();
        $envelope = $recipient->envelope;

        return $this->successResponse([
            'envelope' => [
                'title' => $envelope->title,
                'message' => $envelope->message,
                'status' => $envelope->status->value,
                'required_level' => $envelope->required_level->value,
                'jurisdiction' => $envelope->jurisdiction->value,
                'provider' => $envelope->provider->value,
                'document_format' => $envelope->document_format,
                'locale' => $envelope->locale,
                'expires_at' => $envelope->expires_at?->toIso8601String(),
                'verification_code' => $envelope->verification_code,
                'sandbox' => (bool) ($envelope->metadata['sandbox'] ?? false),
            ],
            'recipient' => [
                'full_name' => $recipient->full_name,
                'role' => $recipient->role->value,
                'status' => $recipient->status->value,
                'auth_method' => $recipient->auth_method->value,
                'phone_hint' => $recipient->phone !== null ? substr($recipient->phone, 0, 4).str_repeat('•', max(0, strlen($recipient->phone) - 6)).substr($recipient->phone, -2) : null,
                'signature_box' => $recipient->signature_box,
                'signed_at' => $recipient->signed_at?->toIso8601String(),
                'can_sign' => $envelope->status->isOpen() && $recipient->status->canAct(),
                'requires_identity_document' => $envelope->provider === ProviderType::Native && $envelope->required_level === SignatureLevel::Fea,
            ],
            'consents' => [
                'terms' => 'signatures.consent.terms',
                'legal' => 'signatures.consent.legal.'.$envelope->required_level->value.'.'.$envelope->jurisdiction->value,
            ],
            'can_download_signed' => $envelope->status === EnvelopeStatus::Completed && $recipient->status === RecipientStatus::Signed,
        ]);
    }

    #[OA\Get(path: '/api/v1/public/sign/{token}/document', operationId: 'signaturesPublicDocument', summary: 'The document the recipient is asked to sign', tags: ['Electronic Signatures Portal'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The PDF')])]
    public function document(Request $request, string $token): Response
    {
        $recipient = $this->signing->resolveToken($token);
        $envelope = $recipient->envelope;
        $signed = $envelope->status === EnvelopeStatus::Completed && $envelope->signed_path !== null;

        if (! $signed && ! $envelope->status->isOpen()) {
            throw new SignatureException('envelope_closed', 410);
        }

        if ($signed) {
            $this->audit->record($envelope, AuditEvent::DocumentDownloaded, ['type' => 'recipient', 'id' => $recipient->id, 'ip' => $request->ip(), 'user_agent' => $request->userAgent()], $recipient, ['recipient_name' => $recipient->full_name]);
        }

        $isPdf = $envelope->document_format === 'pades';
        $name = (Str::slug($envelope->title) ?: 'document').($signed ? '-signed.' : '.').($signed ? $this->storage->extension($envelope) : ($isPdf ? 'pdf' : 'bin'));

        return response($signed ? $this->storage->signed($envelope) : $this->storage->working($envelope), 200, [
            'Content-Type' => $isPdf ? 'application/pdf' : 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[OA\Post(path: '/api/v1/public/sign/{token}/challenge', operationId: 'signaturesPublicChallenge', summary: 'Start the identity check of the recipient (sends the code)', tags: ['Electronic Signatures Portal'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Challenge started')])]
    public function challenge(Request $request, string $token): JsonResponse
    {
        $recipient = $this->signing->resolveToken($token);
        $result = $this->signing->challenge($recipient, $request->ip(), $request->userAgent());

        return $this->successResponse($result->toArray(), 'signatures.message.challengeSent');
    }

    #[OA\Post(path: '/api/v1/public/sign/{token}/sign', operationId: 'signaturesPublicSign', summary: 'Sign the document', tags: ['Electronic Signatures Portal'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Signed or pending at the provider')])]
    public function sign(SignDocumentRequest $request, string $token): JsonResponse
    {
        $recipient = $this->signing->resolveToken($token);
        $data = $request->validated();

        if (($data['signature_method'] ?? '') === 'drawn' && isset($data['signature_data']) && ! str_starts_with((string) $data['signature_data'], 'data:image/png;base64,')) {
            $data['signature_data'] = 'data:image/png;base64,'.$data['signature_data'];
        }

        $result = $this->signing->sign($recipient, [
            'consent_terms' => (bool) $data['consent_terms'],
            'consent_legal' => (bool) $data['consent_legal'],
            'signature_method' => (string) $data['signature_method'],
            'signature_data' => $data['signature_data'] ?? null,
            'otp' => $data['otp'] ?? null,
            'identity' => $data['identity'] ?? null,
        ], $request->ip(), $request->userAgent());

        return $this->successResponse($result, $result['status'] === 'signed' ? 'signatures.message.signed' : 'signatures.message.signingPending');
    }

    #[OA\Post(path: '/api/v1/public/sign/{token}/decline', operationId: 'signaturesPublicDecline', summary: 'Decline to sign, with a reason', tags: ['Electronic Signatures Portal'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Declined')])]
    public function decline(DeclineSignatureRequest $request, string $token): JsonResponse
    {
        $recipient = $this->signing->resolveToken($token);
        $this->signing->decline($recipient, (string) $request->validated('reason'), $request->ip(), $request->userAgent());

        return $this->successResponse(null, 'signatures.message.declined');
    }
}
