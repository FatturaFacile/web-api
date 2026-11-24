<?php

declare(strict_types=1);

namespace Modules\Contracts\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Database\TransactionLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Contracts\Contracts\SignatureProvider;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Models\ContractDocumentVersion;
use Modules\Contracts\Models\ContractSignatureEvent;
use Modules\Contracts\Models\ContractSignatureRequest;
use Modules\Contracts\Models\ContractSigner;

/**
 * E-signature of a contract document version: signers with an order (sequential or parallel), a provider behind the
 * SignatureProvider contract, and an append-only audit trail. Providers are called outside database transactions.
 */
final class SignatureService
{
    public function __construct(private readonly ContractRegister $register) {}

    /**
     * Names of the providers available (the built-in one plus those tagged contracts.signature_providers).
     *
     * @return list<string>
     */
    public function providerNames(): array
    {
        return array_map(static fn (SignatureProvider $provider): string => $provider->name(), $this->providers());
    }

    /**
     * @param  list<array{name: string, email: string}>  $signers
     */
    public function create(ContractKind $kind, string $contractId, ContractDocumentVersion $version, array $signers, string $orderMode, ?string $expiresOn, string $provider, ?string $userId): ContractSignatureRequest
    {
        $this->register->find($kind, $contractId);
        $this->provider($provider);

        if ($version->contract_kind !== $kind->value || $version->contract_id !== $contractId) {
            throw ValidationException::withMessages(['document_version_id' => __('contracts.signature.version_mismatch')]);
        }

        return DB::transaction(function () use ($kind, $contractId, $version, $signers, $orderMode, $expiresOn, $provider, $userId): ContractSignatureRequest {
            TransactionLock::acquire('contract-signature:'.$kind->value.':'.$contractId);

            $open = ContractSignatureRequest::query()->where('document_version_id', $version->getKey())->whereIn('status', ['draft', 'sent'])->exists();

            if ($open) {
                throw ValidationException::withMessages(['document_version_id' => __('contracts.signature.pending_exists')]);
            }

            $request = ContractSignatureRequest::query()->create([
                'contract_kind' => $kind->value,
                'contract_id' => $contractId,
                'document_version_id' => $version->getKey(),
                'provider' => $provider,
                'status' => 'draft',
                'order_mode' => $orderMode,
                'document_hash' => $version->body_hash,
                'expires_on' => $expiresOn,
                'created_by' => $userId,
            ]);

            foreach ($signers as $index => $signer) {
                ContractSigner::query()->create(['request_id' => $request->getKey(), 'name' => $signer['name'], 'email' => $signer['email'], 'position' => $index + 1, 'status' => 'pending']);
            }

            $this->record($request, 'created', null, $userId);

            return $request->load('signers');
        });
    }

    /**
     * Send the request: the provider is called first (no transaction open), then the outcome is stored.
     *
     * @return array{request: ContractSignatureRequest, tokens: array<string, string>}
     */
    public function send(ContractSignatureRequest $request, ?string $userId): array
    {
        if ($request->status !== 'draft') {
            throw ValidationException::withMessages(['status' => __('contracts.signature.only_draft_send')]);
        }

        $request->load('signers');
        $result = $this->provider($request->provider)->dispatch($request);

        DB::transaction(function () use ($request, $result, $userId): void {
            foreach ($request->signers as $signer) {
                $token = $result['tokens'][(string) $signer->getKey()] ?? null;
                $signer->update(['status' => 'notified', 'token_hash' => $token === null ? null : hash('sha256', $token)]);
            }

            $request->update(['status' => 'sent', 'sent_at' => now(), 'provider_reference' => $result['reference']]);
            $this->record($request, 'sent', null, $userId, ['provider' => $request->provider]);
        });

        return ['request' => $request->refresh()->load('signers'), 'tokens' => $result['tokens']];
    }

    /**
     * A signer confirms with the personal token.
     */
    public function sign(ContractSignatureRequest $request, string $token, ?string $ip, ?string $userId): ContractSignatureRequest
    {
        $this->expireIfDue($request);

        return DB::transaction(function () use ($request, $token, $ip, $userId): ContractSignatureRequest {
            TransactionLock::acquire('contract-signature-request:'.$request->getKey());
            $request->refresh()->load('signers');
            $this->assertOpen($request);
            $signer = $this->signerOf($request, $token);

            if ($signer->status === 'signed') {
                throw ValidationException::withMessages(['token' => __('contracts.signature.already_signed')]);
            }

            if ($request->order_mode === 'sequential' && $request->signers->where('position', '<', $signer->position)->contains(static fn (ContractSigner $other): bool => $other->status !== 'signed')) {
                throw ValidationException::withMessages(['token' => __('contracts.signature.sign_in_order')]);
            }

            $signedAt = now();
            $signer->update([
                'status' => 'signed',
                'signed_at' => $signedAt,
                'signature_hash' => hash('sha256', $request->document_hash.'|'.$signer->getKey().'|'.$signedAt->toIso8601String()),
            ]);
            $this->record($request, 'signed', $signer, $userId, ['ip' => $ip, 'document_hash' => $request->document_hash]);

            if (ContractSigner::query()->where('request_id', $request->getKey())->where('status', '!=', 'signed')->doesntExist()) {
                $request->update(['status' => 'completed', 'completed_at' => $signedAt]);
                $this->record($request, 'completed', null, $userId);
            }

            return $request->refresh()->load('signers');
        });
    }

    public function decline(ContractSignatureRequest $request, string $token, string $reason, ?string $userId): ContractSignatureRequest
    {
        $this->expireIfDue($request);

        return DB::transaction(function () use ($request, $token, $reason, $userId): ContractSignatureRequest {
            TransactionLock::acquire('contract-signature-request:'.$request->getKey());
            $request->refresh()->load('signers');
            $this->assertOpen($request);
            $signer = $this->signerOf($request, $token);

            if ($signer->status === 'signed') {
                throw ValidationException::withMessages(['token' => __('contracts.signature.already_signed')]);
            }

            $signer->update(['status' => 'declined', 'decline_reason' => $reason]);
            $request->update(['status' => 'declined']);
            $this->record($request, 'declined', $signer, $userId, ['reason' => $reason]);

            return $request->refresh()->load('signers');
        });
    }

    public function cancel(ContractSignatureRequest $request, ?string $userId): ContractSignatureRequest
    {
        if (! in_array($request->status, ['draft', 'sent'], true)) {
            throw ValidationException::withMessages(['status' => __('contracts.signature.only_open_cancel')]);
        }

        $request->load('signers');

        if ($request->status === 'sent') {
            $this->provider($request->provider)->cancel($request);
        }

        DB::transaction(function () use ($request, $userId): void {
            $request->update(['status' => 'cancelled']);
            $this->record($request, 'cancelled', null, $userId);
        });

        return $request->refresh()->load('signers');
    }

    /**
     * Scheduled pass: every sent request of the company whose expiry date has passed becomes expired, with an audit event.
     */
    public function expireOverdue(string $tenantId, CarbonImmutable $today): int
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return 0;
        }

        return app(TenantContext::class)->runWithTenant($tenant, function () use ($today): int {
            $expired = 0;

            ContractSignatureRequest::query()->where('status', 'sent')->whereNotNull('expires_on')->where('expires_on', '<', $today->toDateString())->get()->each(function (ContractSignatureRequest $request) use (&$expired): void {
                DB::transaction(function () use ($request, &$expired): void {
                    TransactionLock::acquire('contract-signature-request:'.$request->getKey());

                    if ($request->refresh()->status !== 'sent') {
                        return;
                    }

                    $request->update(['status' => 'expired']);
                    $this->record($request, 'expired', null, null, ['source' => 'schedule']);
                    $expired++;
                });
            });

            return $expired;
        });
    }

    /**
     * A request past its expiry date is stored as expired (outside the signing transaction, so the change survives).
     */
    private function expireIfDue(ContractSignatureRequest $request): void
    {
        if ($request->status === 'sent' && $request->expires_on !== null && $request->expires_on->endOfDay()->isPast()) {
            DB::transaction(function () use ($request): void {
                $request->update(['status' => 'expired']);
                $this->record($request, 'expired', null, null);
            });

            throw ValidationException::withMessages(['status' => __('contracts.signature.expired')]);
        }
    }

    private function assertOpen(ContractSignatureRequest $request): void
    {
        if ($request->status !== 'sent') {
            throw ValidationException::withMessages(['status' => __('contracts.signature.not_open')]);
        }
    }

    private function signerOf(ContractSignatureRequest $request, string $token): ContractSigner
    {
        $hash = hash('sha256', $token);
        $signer = $request->signers->first(static fn (ContractSigner $candidate): bool => $candidate->token_hash !== null && hash_equals($candidate->token_hash, $hash));

        if ($signer === null) {
            throw ValidationException::withMessages(['token' => __('contracts.signature.token_invalid')]);
        }

        return $signer;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(ContractSignatureRequest $request, string $event, ?ContractSigner $signer, ?string $actorId, array $metadata = []): void
    {
        ContractSignatureEvent::query()->create([
            'request_id' => $request->getKey(),
            'signer_id' => $signer?->getKey(),
            'event' => $event,
            'actor_id' => $actorId,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    private function provider(string $name): SignatureProvider
    {
        foreach ($this->providers() as $provider) {
            if ($provider->name() === $name) {
                return $provider;
            }
        }

        throw ValidationException::withMessages(['provider' => __('contracts.signature.provider_unknown')]);
    }

    /**
     * @return list<SignatureProvider>
     */
    private function providers(): array
    {
        $providers = [app(InternalSignatureProvider::class)];

        foreach (app()->tagged('contracts.signature_providers') as $provider) {
            $providers[] = $provider;
        }

        return $providers;
    }
}
