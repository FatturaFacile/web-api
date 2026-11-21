<?php

declare(strict_types=1);

namespace Modules\Quoting\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Quoting\Application\Commands\CompleteQuoteSignatureCommand;
use Modules\Quoting\Application\Commands\InitiateQuoteSignatureCommand;
use Modules\Quoting\Application\Commands\PostQuoteCommentCommand;
use Modules\Quoting\Application\Commands\RejectQuoteCommand;
use Modules\Quoting\Application\Commands\UpdateCustomerQuoteSelectionsCommand;
use Modules\Quoting\Application\Commands\VerifyPublicQuoteAccessCommand;
use Modules\Quoting\Application\Handlers\CompleteQuoteSignatureHandler;
use Modules\Quoting\Application\Handlers\GetPublicQuoteProposalHandler;
use Modules\Quoting\Application\Handlers\InitiateQuoteSignatureHandler;
use Modules\Quoting\Application\Handlers\PostQuoteCommentHandler;
use Modules\Quoting\Application\Handlers\RejectQuoteHandler;
use Modules\Quoting\Application\Handlers\UpdateCustomerQuoteSelectionsHandler;
use Modules\Quoting\Application\Handlers\VerifyPublicQuoteAccessHandler;
use Modules\Quoting\Application\Queries\GetPublicQuoteProposalQuery;
use Modules\Quoting\Domain\Exceptions\QuoteAccessDeniedException;
use Modules\Quoting\Domain\Exceptions\QuoteException;
use Modules\Quoting\Infrastructure\Services\PublicQuoteGate;
use Modules\Quoting\Presentation\Http\Requests\PublicProposalRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public proposal portal. No account: the unguessable token of the URL (plus an optional PIN session) is the credential.
 * Every refusal of the link answers the same 404, whatever the reason (unknown, expired, superseded, wrong PIN gate).
 */
final class PublicProposalController extends Controller
{
    use ApiResponse;

    private const string SessionHeader = 'X-Proposal-Session';

    #[OA\Get(path: '/api/v1/public/proposals/{token}', operationId: 'getPublicProposal', summary: 'The customer-facing proposal (no costs, no margins)', tags: ['Quoting Portal'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The proposal'), new OA\Response(response: 404, description: 'Invalid, expired or superseded link')])]
    public function show(string $token, Request $request, GetPublicQuoteProposalHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new GetPublicQuoteProposalQuery($token, $this->session($request), $request->ip()))));
    }

    #[OA\Post(path: '/api/v1/public/proposals/{token}/verify-pin', operationId: 'verifyPublicProposalPin', summary: 'Exchange the PIN for a short-lived session token', tags: ['Quoting Portal'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The session token')])]
    public function verifyPin(string $token, PublicProposalRequest $request, VerifyPublicQuoteAccessHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(['session' => $handler->handle(new VerifyPublicQuoteAccessCommand($token, (string) $request->validated('pin'), $request->ip()))]));
    }

    #[OA\Put(path: '/api/v1/public/proposals/{token}/selections', operationId: 'updatePublicProposalSelections', summary: 'Toggle optional lines and pick alternatives; returns the recalculated proposal', tags: ['Quoting Portal'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The recalculated proposal')])]
    public function selections(string $token, PublicProposalRequest $request, UpdateCustomerQuoteSelectionsHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new UpdateCustomerQuoteSelectionsCommand(
            $token, $this->session($request), array_map(static fn ($v): bool => (bool) $v, (array) $request->validated('selections')), $request->ip(),
        ))));
    }

    #[OA\Post(path: '/api/v1/public/proposals/{token}/signature', operationId: 'initiatePublicProposalSignature', summary: 'Start the signature: sends the OTP (SES) or returns the TSP ceremony URL (AES/QES)', tags: ['Quoting Portal'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The envelope and the next step')])]
    public function initiateSignature(string $token, PublicProposalRequest $request, InitiateQuoteSignatureHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new InitiateQuoteSignatureCommand(
            $token, $this->session($request), (string) $data['signer_name'], $data['signer_role'] ?? null, $data['signer_email'] ?? null, $data['signer_phone'] ?? null,
            (string) ($data['otp_channel'] ?? 'email'), true, $request->ip(), $request->userAgent(),
        ))));
    }

    #[OA\Post(path: '/api/v1/public/proposals/{token}/signature/complete', operationId: 'completePublicProposalSignature', summary: 'Confirm the one-time code: accepts the quote and builds the signed PDF and the audit certificate', tags: ['Quoting Portal'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Accepted, with the SHA-256 of the signed documents')])]
    public function completeSignature(string $token, PublicProposalRequest $request, CompleteQuoteSignatureHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new CompleteQuoteSignatureCommand(
            $token, $this->session($request), (string) $request->validated('envelope_id'), ['otp' => (string) $request->validated('otp')], $request->ip(), $request->userAgent(),
        ))));
    }

    #[OA\Post(path: '/api/v1/public/proposals/{token}/reject', operationId: 'rejectPublicProposal', summary: 'Reject the proposal with a mandatory reason', tags: ['Quoting Portal'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Rejected')])]
    public function reject(string $token, PublicProposalRequest $request, RejectQuoteHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($token, $request, $handler): JsonResponse {
            $handler->handle(new RejectQuoteCommand($token, $this->session($request), (string) $request->validated('reason'), $request->ip()));

            return $this->successResponse(['status' => 'rejected']);
        });
    }

    #[OA\Post(path: '/api/v1/public/proposals/{token}/comments', operationId: 'commentPublicProposal', summary: 'Ask the sales rep a question', tags: ['Quoting Portal'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 201, description: 'Posted')])]
    public function comment(string $token, PublicProposalRequest $request, PostQuoteCommentHandler $handler): JsonResponse
    {
        return $this->guarded(function () use ($token, $request, $handler): JsonResponse {
            $data = $request->validated();
            $comment = $handler->handle(new PostQuoteCommentCommand($token, $this->session($request), (string) $data['body'], $data['line_id'] ?? null, $data['author_name'] ?? null, $request->ip()));

            return $this->successResponse(['id' => $comment->id], statusCode: Response::HTTP_CREATED);
        });
    }

    #[OA\Get(path: '/api/v1/public/proposals/{token}/documents/{kind}', operationId: 'downloadPublicProposalDocument', summary: 'Download the signed PDF/A proposal or the audit certificate of an accepted quote', tags: ['Quoting Portal'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'The PDF')])]
    public function document(string $token, string $kind, Request $request, PublicQuoteGate $gate): JsonResponse|StreamedResponse
    {
        try {
            $quote = $gate->resolve($token, $this->session($request), allowClosed: true);
        } catch (QuoteAccessDeniedException) {
            return $this->notFound();
        }

        $path = $kind === 'certificate' ? $quote->audit_certificate_path : ($kind === 'proposal' ? $quote->signed_document_path : null);
        $disk = Storage::disk((string) config('quoting.storage_disk', 'local'));

        if ($path === null || ! $disk->exists($path)) {
            return $this->notFound();
        }

        return $disk->response($path, $quote->quote_number.'-'.$kind.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    #[OA\Post(path: '/api/v1/public/signature-webhooks', operationId: 'receiveSignatureProviderWebhook', summary: 'Callback of the trust service provider (AES/QES), authenticated by HMAC-SHA256 of the raw body', tags: ['Quoting Portal'], responses: [new OA\Response(response: 200, description: 'Processed (idempotent)'), new OA\Response(response: 403, description: 'Signature not authentic')])]
    public function webhook(Request $request, CompleteQuoteSignatureHandler $handler): JsonResponse
    {
        $payload = $request->getContent();
        $data = json_decode($payload, true);
        $reference = is_array($data) ? (string) ($data['reference'] ?? '') : '';

        try {
            return $this->successResponse($handler->handleWebhook($reference, $payload, (string) $request->header('X-Signature', ''), $request->ip()));
        } catch (QuoteAccessDeniedException) {
            return $this->problemDetailsResponse('Forbidden', 'The callback is not authentic.', Response::HTTP_FORBIDDEN);
        }
    }

    private function session(Request $request): ?string
    {
        $session = $request->header(self::SessionHeader);

        return is_string($session) && $session !== '' ? $session : null;
    }

    private function notFound(): JsonResponse
    {
        return $this->problemDetailsResponse('Not Found', 'This proposal link is not valid.', Response::HTTP_NOT_FOUND);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T|JsonResponse
     */
    private function guarded(Closure $action): mixed
    {
        try {
            return $action();
        } catch (QuoteAccessDeniedException) {
            return $this->notFound();
        } catch (QuoteException $exception) {
            return $this->problemDetailsResponse('Quote Rule Violation', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
