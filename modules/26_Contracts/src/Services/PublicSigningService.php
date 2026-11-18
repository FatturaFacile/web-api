<?php

declare(strict_types=1);

namespace Modules\Contracts\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Modules\Contracts\Models\ContractDocumentVersion;
use Modules\Contracts\Models\ContractSignatureRequest;
use Modules\Contracts\Models\ContractSigner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Token-based access of a signer who has no account: the secret token of the link identifies the signer, the request
 * and the company. Only the SHA-256 hash of the token is stored and looked up; every failure is the same 404.
 */
final class PublicSigningService
{
    public function __construct(private readonly TenantContext $tenantContext, private readonly SignatureService $signatures) {}

    /**
     * Resolve the link and activate the company of the request.
     *
     * @return array{signer: ContractSigner, request: ContractSignatureRequest}
     */
    public function resolve(string $token): array
    {
        $token = trim($token);
        $signer = $token === '' ? null : ContractSigner::query()->withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();
        $request = $signer === null ? null : ContractSignatureRequest::query()->withoutGlobalScopes()->find($signer->request_id);
        $tenant = $request === null ? null : Tenant::query()->find($request->tenant_id);

        if ($signer === null || $request === null || $tenant === null) {
            throw new NotFoundHttpException(__('contracts.signature.link_invalid'));
        }

        $this->tenantContext->setTenant($tenant);

        return ['signer' => $signer, 'request' => $request];
    }

    /**
     * What the signer sees: the text to sign, who signs and whether it is their turn.
     *
     * @return array<string, mixed>
     */
    public function view(string $token): array
    {
        ['signer' => $signer, 'request' => $request] = $this->resolve($token);
        $request->load('signers');
        $version = ContractDocumentVersion::query()->find($request->document_version_id);
        $waiting = $request->order_mode === 'sequential' && $request->signers->where('position', '<', $signer->position)->contains(static fn (ContractSigner $other): bool => $other->status !== 'signed');
        $expired = $request->status === 'sent' && $request->expires_on !== null && $request->expires_on->endOfDay()->isPast();

        return [
            'status' => $expired ? 'expired' : $request->status,
            'signer_status' => $signer->status,
            'signer_name' => $signer->name,
            'can_sign' => $request->status === 'sent' && ! $expired && $signer->status === 'notified' && ! $waiting,
            'waiting_for_previous' => $waiting,
            'expires_on' => $request->expires_on?->toDateString(),
            'document_hash' => $request->document_hash,
            'body' => $version->body ?? '',
            'company_name' => $this->tenantContext->getTenant()?->name,
            'signers' => $request->signers->map(static fn (ContractSigner $row): array => ['name' => $row->name, 'position' => $row->position, 'status' => $row->status])->all(),
        ];
    }

    public function sign(string $token, ?string $ip): void
    {
        ['request' => $request] = $this->resolve($token);
        $this->signatures->sign($request, trim($token), $ip, null);
    }

    public function decline(string $token, string $reason): void
    {
        ['request' => $request] = $this->resolve($token);
        $this->signatures->decline($request, trim($token), $reason, null);
    }
}
