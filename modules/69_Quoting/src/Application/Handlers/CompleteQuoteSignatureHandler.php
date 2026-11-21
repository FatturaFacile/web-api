<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Quoting\Application\Commands\CompleteQuoteSignatureCommand;
use Modules\Quoting\Application\Services\SignatureAdapterResolver;
use Modules\Quoting\Domain\Enums\QuoteStatus;
use Modules\Quoting\Domain\Events\QuoteSignedAndAccepted;
use Modules\Quoting\Domain\Exceptions\QuoteAccessDeniedException;
use Modules\Quoting\Infrastructure\Models\CpqQuote;
use Modules\Quoting\Infrastructure\Models\CpqSignatureEnvelope;
use Modules\Quoting\Infrastructure\Services\PublicQuoteGate;
use Modules\Quoting\Infrastructure\Services\QuoteAuditTrail;
use Modules\Quoting\Infrastructure\Services\QuoteDocuments;
use Modules\Quoting\Infrastructure\Services\QuoteSnapshotBuilder;

/**
 * Closes the signature: verifies the proof (OTP code or the HMAC-signed callback of the trust service provider), locks the
 * customer's selections into a PDF/A proposal with its SHA-256, writes the audit event, accepts the quote and builds the
 * certificate of completion. Idempotent: a repeated callback or a double click returns the accepted quote untouched.
 */
final class CompleteQuoteSignatureHandler
{
    public function __construct(
        private readonly PublicQuoteGate $gate,
        private readonly SignatureAdapterResolver $adapters,
        private readonly QuoteDocuments $documents,
        private readonly QuoteSnapshotBuilder $snapshots,
        private readonly QuoteAuditTrail $audit,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array{quote_number: string, status: string, signed_document_hash: string|null, audit_certificate_hash: string|null}
     */
    public function handle(CompleteQuoteSignatureCommand $command): array
    {
        $quote = $this->gate->resolve($command->token, $command->session, allowClosed: true);
        $envelope = CpqSignatureEnvelope::query()->where('quote_id', $quote->id)->findOrFail($command->envelopeId);

        if ($envelope->status === 'signed') {
            return $this->summary($quote);
        }

        $metadata = $this->adapters->for($envelope->level)->verify($envelope, $command->proof);

        return $this->finalize($quote, $envelope, $metadata, $command->ip, $command->userAgent);
    }

    /**
     * Trust service provider callback: no token, authenticated by the HMAC of the raw body.
     *
     * @return array{quote_number: string, status: string, signed_document_hash: string|null, audit_certificate_hash: string|null}
     */
    public function handleWebhook(string $providerReference, string $rawPayload, string $signature, ?string $ip): array
    {
        $envelope = CpqSignatureEnvelope::withoutGlobalScopes()->where('provider_reference', $providerReference)->first();

        if (! $envelope instanceof CpqSignatureEnvelope) {
            throw new QuoteAccessDeniedException('Unknown signature reference.');
        }

        $tenant = Tenant::query()->find($envelope->tenant_id);
        if (! $tenant instanceof Tenant) {
            throw new QuoteAccessDeniedException('Unknown signature reference.');
        }

        $this->tenantContext->setTenant($tenant);
        $envelope = CpqSignatureEnvelope::query()->findOrFail($envelope->id);
        $metadata = $this->adapters->for($envelope->level)->verify($envelope, ['payload' => $rawPayload, 'signature' => $signature]);
        $quote = CpqQuote::query()->findOrFail($envelope->quote_id);

        if ($envelope->status === 'signed') {
            return $this->summary($quote);
        }

        return $this->finalize($quote, $envelope, $metadata, $ip, null);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{quote_number: string, status: string, signed_document_hash: string|null, audit_certificate_hash: string|null}
     */
    private function finalize(CpqQuote $quote, CpqSignatureEnvelope $envelope, array $metadata, ?string $ip, ?string $userAgent): array
    {
        if (! $quote->status->isOpenForCustomer()) {
            return $this->summary($quote);
        }

        /** @var array<string, bool> $selections */
        $selections = (array) $quote->customer_selections;
        $signed = $this->documents->storeSignedProposal($quote, $selections);
        $view = $this->snapshots->publicView($quote, $selections);
        $accepted = false;

        DB::transaction(function () use ($quote, $envelope, $metadata, $ip, $userAgent, $signed, $view, &$accepted): void {
            $locked = CpqQuote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpenForCustomer()) {
                return;
            }

            $envelope->forceFill([
                'status' => 'signed',
                'signed_at' => now(),
                'document_hash' => $signed['hash']->value,
                'signer_ip' => $ip ?? $envelope->signer_ip,
                'signer_user_agent' => $userAgent === null ? $envelope->signer_user_agent : mb_substr($userAgent, 0, 500),
                'verification_metadata' => $metadata + ['pdfa_compliant' => $signed['pdfa_compliant']],
            ])->save();

            $this->audit->record($locked, 'signed', $envelope->signer_name, $ip, [
                'envelope_id' => $envelope->id, 'level' => $envelope->level->value, 'provider' => $envelope->provider,
                'provider_reference' => $envelope->provider_reference, 'document_hash' => $signed['hash']->value, 'pdfa_compliant' => $signed['pdfa_compliant'],
            ]);

            $locked->forceFill([
                'status' => QuoteStatus::Accepted->value,
                'accepted_at' => now(),
                'public_pin_hash' => $locked->public_pin_hash,
                'signed_document_path' => $signed['path'],
                'signed_document_hash' => $signed['hash']->value,
                'total_net' => $view['totals']['net'],
                'total_tax' => $view['totals']['tax'],
                'total_gross' => $view['totals']['payable'],
            ])->save();
            $accepted = true;
        });

        $quote->refresh();

        if ($accepted) {
            $certificate = $this->documents->storeCertificate($quote, $envelope->refresh());
            $quote->forceFill(['audit_certificate_path' => $certificate['path'], 'audit_certificate_hash' => $certificate['hash']->value])->save();
            event(new QuoteSignedAndAccepted($quote->id, $envelope->id));
        }

        return $this->summary($quote);
    }

    /**
     * @return array{quote_number: string, status: string, signed_document_hash: string|null, audit_certificate_hash: string|null}
     */
    private function summary(CpqQuote $quote): array
    {
        return [
            'quote_number' => $quote->quote_number,
            'status' => $quote->status->value,
            'signed_document_hash' => $quote->signed_document_hash,
            'audit_certificate_hash' => $quote->audit_certificate_hash,
        ];
    }
}
