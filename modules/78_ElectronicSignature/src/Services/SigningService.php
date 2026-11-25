<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\DocumentTrust\Contracts\TrustedTimestampProvider;
use Modules\ElectronicSignature\DTOs\ChallengeResult;
use Modules\ElectronicSignature\DTOs\SignatureAppearance;
use Modules\ElectronicSignature\DTOs\SigningInput;
use Modules\ElectronicSignature\DTOs\SigningResult;
use Modules\ElectronicSignature\DTOs\WebhookEvent;
use Modules\ElectronicSignature\Enums\AuditEvent;
use Modules\ElectronicSignature\Enums\EnvelopeStatus;
use Modules\ElectronicSignature\Enums\ProviderType;
use Modules\ElectronicSignature\Enums\RecipientStatus;
use Modules\ElectronicSignature\Enums\RoutingMode;
use Modules\ElectronicSignature\Enums\SignatureLevel;
use Modules\ElectronicSignature\Events\SignatureEnvelopeCompleted;
use Modules\ElectronicSignature\Events\SignatureEnvelopeDeclined;
use Modules\ElectronicSignature\Events\SignatureEnvelopeExpired;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Models\SignatureProviderConfig;
use Modules\ElectronicSignature\Models\SignatureRecipient;
use Throwable;

/**
 * The ceremony of the recipients and the life of an envelope once it is sent: link resolution, identity challenge,
 * signature, decline, routing to the next recipients, completion (sealing, time stamp, certificate, e-mails), expiry,
 * and the events that arrive from the providers. Every change of the working copy of an envelope runs under a lock, so two
 * signers cannot write over each other's signature.
 */
final class SigningService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly EnvelopeStorage $storage,
        private readonly PdfSignatureService $pdf,
        private readonly SignatureAuditService $audit,
        private readonly SignatureNotifier $notifier,
        private readonly SignatureTextResolver $texts,
        private readonly AuditCertificateService $certificate,
        private readonly TenantContext $tenantContext,
    ) {}

    // --- Public link ---

    /**
     * Resolves the unguessable token of a signing link to its recipient and puts the tenant of the envelope in the request
     * context. An unknown token is a 404 without detail; a known one that has expired says so.
     *
     * @throws SignatureException
     */
    public function resolveToken(string $token): SignatureRecipient
    {
        $found = SignatureRecipient::withoutGlobalScopes()->where('access_token_hash', SignatureRecipient::hashToken($token))->first();

        if (! $found instanceof SignatureRecipient) {
            throw new SignatureException('link_invalid', 404);
        }

        $tenant = Tenant::query()->find($found->tenant_id);

        if (! $tenant instanceof Tenant) {
            throw new SignatureException('link_invalid', 404);
        }

        $this->tenantContext->setTenant($tenant);
        $recipient = SignatureRecipient::query()->with('envelope')->findOrFail($found->id);

        if ($recipient->token_expires_at !== null && $recipient->token_expires_at->isPast() && ! $recipient->envelope->status->isFinal()) {
            $this->expireIfDue($recipient->envelope);
        }

        return $recipient->refresh()->loadMissing('envelope');
    }

    /**
     * Records that the recipient opened the document.
     */
    public function markViewed(SignatureRecipient $recipient, ?string $ip, ?string $userAgent): void
    {
        $envelope = $recipient->envelope;

        if (! $envelope->status->isOpen() || ! $recipient->status->canAct() || $recipient->viewed_at !== null) {
            return;
        }

        $recipient->forceFill(['viewed_at' => now(), 'status' => RecipientStatus::Viewed])->save();
        $this->audit->record($envelope, AuditEvent::DocumentViewed, $this->actor($recipient, $ip, $userAgent), $recipient, documentSha256: $envelope->original_sha256);
    }

    /**
     * Starts the identity check of the recipient (sends the code, pushes to the phone, or builds the provider URL).
     */
    public function challenge(SignatureRecipient $recipient, ?string $ip, ?string $userAgent): ChallengeResult
    {
        $envelope = $this->assertCanAct($recipient);
        $config = $this->config($envelope);
        $result = $this->providers->provider($envelope->provider)->startChallenge($envelope, $recipient, $config);

        $this->audit->record($envelope, AuditEvent::ChallengeSent, $this->actor($recipient, $ip, $userAgent), $recipient, [
            'method' => $recipient->auth_method->value,
            'channel' => $result->channel,
            'type' => $result->type,
        ]);

        return $result;
    }

    /**
     * Signs for the recipient.
     *
     * @param  array{consent_terms: bool, consent_legal: bool, signature_method: string, signature_data?: string|null, otp?: string|null, identity?: array<string, string>|null}  $input
     * @return array{status: string, envelope_status: string}
     */
    public function sign(SignatureRecipient $recipient, array $input, ?string $ip, ?string $userAgent): array
    {
        return $this->locked($recipient->envelope, function () use ($recipient, $input, $ip, $userAgent): array {
            $envelope = $this->assertCanAct($recipient->refresh());

            if (! $input['consent_terms'] || ! $input['consent_legal']) {
                throw new SignatureException('consent_required');
            }

            $identity = (array) ($input['identity'] ?? []);

            if ($envelope->provider === ProviderType::Native && $envelope->required_level === SignatureLevel::Fea
                && (($identity['id_document_type'] ?? '') === '' || ($identity['id_document_number'] ?? '') === '')) {
                throw new SignatureException('identity_document_required');
            }

            if ($identity !== []) {
                $recipient->forceFill(['identity_metadata' => array_merge($recipient->identity_metadata ?? [], $identity)])->save();
            }

            $recipient->forceFill(['consent_at' => now()])->save();
            $actor = $this->actor($recipient, $ip, $userAgent);
            $this->audit->record($envelope, AuditEvent::ConsentGiven, $actor, $recipient, ['recipient_name' => $recipient->full_name, 'consents' => ['terms', 'legal_effect']]);

            $config = $this->config($envelope);
            $signingInput = new SigningInput(
                $this->appearance($envelope, $recipient),
                $this->storage->working($envelope),
                $input['otp'] ?? null,
                $input['signature_method'],
                $input['signature_data'] ?? null,
                $ip,
                $userAgent,
            );

            try {
                $result = $this->providers->provider($envelope->provider)->sign($envelope, $recipient, $config, $signingInput);
            } catch (SignatureException $exception) {
                $this->audit->record($envelope, AuditEvent::ChallengeFailed, $actor, $recipient, ['code' => $exception->errorCode()]);

                throw $exception;
            }

            return $this->applyResult($envelope, $recipient, $result, $actor, $input['signature_data'] ?? null, $input['signature_method']);
        });
    }

    /**
     * The recipient declines to sign; the reason is mandatory and ends the envelope.
     */
    public function decline(SignatureRecipient $recipient, string $reason, ?string $ip, ?string $userAgent): void
    {
        $this->locked($recipient->envelope, function () use ($recipient, $reason, $ip, $userAgent): void {
            $envelope = $this->assertCanAct($recipient->refresh());

            if (trim($reason) === '') {
                throw new SignatureException('decline_reason_required');
            }

            $this->declineRecipient($envelope, $recipient, trim($reason), $this->actor($recipient, $ip, $userAgent));
        });
    }

    // --- Routing ---

    /**
     * Opens the envelope to its first recipients (all of them for parallel routing).
     */
    public function startRouting(SignatureEnvelope $envelope): void
    {
        $this->activateNext($envelope->refresh());
    }

    /**
     * Notifies the recipients that are waiting for their turn to sign again with the link they already have.
     */
    public function remindPending(SignatureEnvelope $envelope, array $actor): int
    {
        $count = 0;

        foreach ($envelope->actingRecipients()->get() as $recipient) {
            if ($recipient->status->canAct() && $recipient->access_token !== null) {
                $this->notifier->sendReminder($envelope, $recipient, $recipient->access_token);
                $this->audit->record($envelope, AuditEvent::ReminderSent, $actor, $recipient, ['recipient_name' => $recipient->full_name]);
                $count++;
            }
        }

        if ($count > 0) {
            $envelope->forceFill(['last_reminder_at' => now()])->save();
        }

        return $count;
    }

    // --- Provider events ---

    /**
     * Applies an authenticated callback of a provider to its envelope.
     */
    public function applyWebhook(SignatureEnvelope $envelope, WebhookEvent $event, ?string $ip): void
    {
        $actor = ['type' => 'provider', 'id' => $envelope->provider->value, 'ip' => $ip];

        $this->locked($envelope, function () use ($envelope, $event, $actor): void {
            $envelope->refresh();

            if ($envelope->status->isFinal()) {
                return;
            }

            $this->audit->record($envelope, AuditEvent::ProviderCallback, $actor, null, ['event' => $event->type, 'event_id' => $event->eventId]);
            $recipient = $event->recipientExternalId !== null
                ? $envelope->recipients()->get()->first(static fn (SignatureRecipient $candidate): bool => strcasecmp($candidate->email, (string) $event->recipientExternalId) === 0 || $candidate->external_id === $event->recipientExternalId)
                : null;

            if ($event->type === WebhookEvent::RecipientSigned && $recipient !== null && ! in_array($recipient->status, [RecipientStatus::Signed, RecipientStatus::Declined], true)) {
                $this->applyResult(
                    $envelope,
                    $recipient,
                    new SigningResult(SigningResult::Signed, $envelope->provider->value.'_'.strtolower($envelope->required_level->value), certificateSerial: $event->certificateSerial, externalId: $event->eventId, evidence: ['callback' => true]),
                    $actor,
                    null,
                    'provider',
                );
            } elseif ($event->type === WebhookEvent::RecipientRejected && $recipient !== null && $recipient->status->canAct()) {
                $this->declineRecipient($envelope, $recipient, (string) ($event->reason ?? ''), $actor);
            } elseif ($event->type === WebhookEvent::EnvelopeCompleted) {
                $this->completeFromProvider($envelope, $actor);
            } elseif ($event->type === WebhookEvent::EnvelopeExpired) {
                $this->expire($envelope);
            }
        });
    }

    /**
     * Asks the provider for the signatures that were left pending (step-up not yet confirmed).
     */
    public function pollPending(SignatureEnvelope $envelope): void
    {
        $this->locked($envelope, function () use ($envelope): void {
            $envelope->refresh();

            foreach ($envelope->actingRecipients()->where('status', RecipientStatus::Signing->value)->get() as $recipient) {
                if (! $envelope->status->isOpen()) {
                    return;
                }

                try {
                    $result = $this->providers->provider($envelope->provider)->poll($envelope, $recipient, $this->config($envelope));
                } catch (SignatureException $exception) {
                    Log::warning('electronic-signature.poll.failed', ['envelope' => $envelope->id, 'code' => $exception->errorCode()]);

                    continue;
                }

                if ($result->isSigned()) {
                    $this->applyResult($envelope, $recipient, $result, ['type' => 'provider', 'id' => $envelope->provider->value], null, 'provider');
                }
            }
        });
    }

    // --- Expiry ---

    public function expireIfDue(SignatureEnvelope $envelope): bool
    {
        if ($envelope->status->isOpen() && $envelope->isExpired()) {
            $this->locked($envelope, function () use ($envelope): void {
                $envelope->refresh();

                if ($envelope->status->isOpen() && $envelope->isExpired()) {
                    $this->expire($envelope);
                }
            });

            return true;
        }

        return false;
    }

    public function expire(SignatureEnvelope $envelope): void
    {
        DB::transaction(function () use ($envelope): void {
            $envelope->forceFill(['status' => EnvelopeStatus::Expired])->save();
            $envelope->recipients()->whereNotIn('status', [RecipientStatus::Signed->value, RecipientStatus::Declined->value])->update(['status' => RecipientStatus::Cancelled->value]);
            $this->audit->record($envelope, AuditEvent::EnvelopeExpired, ['type' => 'system'], null, ['expires_at' => $envelope->expires_at?->toIso8601String()]);
        });

        $this->cancelAtProvider($envelope);
        $this->safely(fn () => $this->notifier->sendExpired($envelope));
        SignatureEnvelopeExpired::dispatch($envelope);
    }

    // --- Internals ---

    /**
     * @param  array{type?: string, id?: string|null, ip?: string|null, user_agent?: string|null}  $actor
     * @return array{status: string, envelope_status: string}
     */
    private function applyResult(SignatureEnvelope $envelope, SignatureRecipient $recipient, SigningResult $result, array $actor, ?string $signatureData, string $signatureMethod): array
    {
        if (! $result->isSigned()) {
            $path = null;

            if ($result->preparedPdf !== null) {
                $path = $this->storage->directory($envelope).'/pending-'.$recipient->id.'.pdf';
                $this->storage->put($path, $result->preparedPdf);
            }

            $recipient->forceFill(['status' => RecipientStatus::Signing, 'prepared_path' => $path, 'external_id' => $result->externalId ?? $recipient->external_id])->save();
            $this->audit->record($envelope, AuditEvent::ChallengeSent, $actor, $recipient, ['state' => 'pending', 'recipient_name' => $recipient->full_name]);

            return ['status' => 'pending', 'envelope_status' => $envelope->status->value];
        }

        $documentHash = $envelope->original_sha256;

        if ($result->pdf !== null) {
            $count = $envelope->recipients()->where('status', RecipientStatus::Signed->value)->count() + 1;
            $path = $this->storage->directory($envelope).'/working-'.$count.'.'.$this->storage->extension($envelope);
            $this->storage->put($path, $result->pdf);
            $documentHash = $this->pdf->sha256($result->pdf);
            $envelope->forceFill(['working_path' => $path])->save();
        }

        $this->storage->delete($recipient->prepared_path);

        DB::transaction(function () use ($envelope, $recipient, $result, $actor, $signatureData, $signatureMethod, $documentHash): void {
            $recipient->forceFill([
                'status' => RecipientStatus::Signed,
                'signed_at' => now(),
                'signature_method' => $signatureMethod === 'provider' ? $result->method : $signatureMethod.':'.$result->method,
                'signature_data' => $this->storedSignatureData($signatureData),
                'certificate_serial' => $result->certificateSerial,
                'certificate_subject' => $result->certificateSubject,
                'external_id' => $result->externalId ?? $recipient->external_id,
                'prepared_path' => null,
                'otp_hash' => null,
            ])->save();

            $this->audit->record($envelope, AuditEvent::IdentityVerified, $actor, $recipient, ['method' => $recipient->auth_method->value, 'recipient_name' => $recipient->full_name] + $result->evidence);
            $this->audit->record($envelope, AuditEvent::RecipientSigned, $actor, $recipient, [
                'recipient_name' => $recipient->full_name,
                'role' => $recipient->role->value,
                'method' => $result->method,
                'certificate_serial' => $result->certificateSerial,
            ], $documentHash, $result->receipt);
        });

        $this->advance($envelope);

        return ['status' => 'signed', 'envelope_status' => $envelope->refresh()->status->value];
    }

    /**
     * After a signature: complete the envelope when everyone has signed, otherwise open the next recipients.
     */
    private function advance(SignatureEnvelope $envelope): void
    {
        $envelope->refresh();
        $acting = $envelope->actingRecipients()->get();

        if ($acting->every(static fn (SignatureRecipient $recipient): bool => $recipient->status === RecipientStatus::Signed)) {
            $this->complete($envelope);

            return;
        }

        $envelope->forceFill(['status' => EnvelopeStatus::PartiallySigned])->save();
        $this->activateNext($envelope);
    }

    /**
     * Gives the link to the recipients whose turn it is: the lowest signing order that has not signed yet for sequential
     * routing, everybody for parallel routing.
     */
    private function activateNext(SignatureEnvelope $envelope): void
    {
        $open = $envelope->actingRecipients()->get()->reject(static fn (SignatureRecipient $recipient): bool => $recipient->status === RecipientStatus::Signed);

        if ($open->isEmpty()) {
            return;
        }

        $turn = $envelope->routing_mode === RoutingMode::Parallel ? $open : $open->where('signing_order', $open->min('signing_order'));

        foreach ($turn as $recipient) {
            if ($recipient->status === RecipientStatus::Waiting) {
                $this->activate($envelope, $recipient);
            }
        }
    }

    private function activate(SignatureEnvelope $envelope, SignatureRecipient $recipient): void
    {
        $token = $recipient->access_token ?? Str::random(48);

        $recipient->forceFill([
            'access_token' => $token,
            'access_token_hash' => SignatureRecipient::hashToken($token),
            'token_expires_at' => $envelope->expires_at,
            'status' => RecipientStatus::Pending,
            'notified_at' => now(),
        ])->save();

        $this->notifier->sendRequest($envelope, $recipient, $token);
    }

    private function complete(SignatureEnvelope $envelope): void
    {
        $config = $this->config($envelope);
        $provider = $this->providers->provider($envelope->provider);
        $signed = $provider->finalize($envelope, $config, $this->storage->working($envelope), $this->stamps($envelope));
        $hash = $this->pdf->sha256($signed);
        $path = $this->storage->directory($envelope).'/signed.'.$this->storage->extension($envelope);
        $this->storage->put($path, $signed);

        $token = null;

        try {
            $token = app(TrustedTimestampProvider::class)->timestamp($hash);
        } catch (Throwable $exception) {
            Log::warning('electronic-signature.timestamp.failed', ['envelope' => $envelope->id, 'error' => $exception->getMessage()]);
        }

        DB::transaction(function () use ($envelope, $path, $hash, $token): void {
            $envelope->forceFill(['status' => EnvelopeStatus::Completed, 'completed_at' => now(), 'signed_path' => $path, 'signed_sha256' => $hash])->save();
            $system = ['type' => 'system'];
            $this->audit->record($envelope, AuditEvent::EnvelopeSealed, $system, null, ['format' => $envelope->document_format], $hash);

            if ($token !== null) {
                $this->audit->record($envelope, AuditEvent::TimestampApplied, $system, null, ['provider' => $token->provider, 'qualified' => $token->qualified, 'issued_at' => $token->issuedAt->format(DATE_ATOM)], $hash, $token->provider, $token->token);
            } else {
                $this->audit->record($envelope, AuditEvent::TimestampUnavailable, $system, null, [], $hash);
            }

            $this->audit->record($envelope, AuditEvent::EnvelopeCompleted, $system, null, [], $hash);
        });

        $this->deliverCompletion($envelope->refresh(), $signed);
        SignatureEnvelopeCompleted::dispatch($envelope);
    }

    /**
     * @param  array{type?: string, id?: string|null, ip?: string|null, user_agent?: string|null}  $actor
     */
    private function completeFromProvider(SignatureEnvelope $envelope, array $actor): void
    {
        foreach ($envelope->actingRecipients()->get() as $recipient) {
            if ($recipient->status !== RecipientStatus::Signed) {
                $recipient->forceFill(['status' => RecipientStatus::Signed, 'signed_at' => now(), 'signature_method' => 'provider'])->save();
                $this->audit->record($envelope, AuditEvent::RecipientSigned, $actor, $recipient, ['recipient_name' => $recipient->full_name, 'role' => $recipient->role->value, 'via' => 'envelope_completed']);
            }
        }

        $this->complete($envelope);
    }

    private function deliverCompletion(SignatureEnvelope $envelope, string $signed): void
    {
        $this->safely(function () use ($envelope, $signed): void {
            $certificate = $this->certificate->render($envelope->load(['recipients', 'auditLogs']));

            foreach ($envelope->recipients as $recipient) {
                $this->notifier->sendCompleted($envelope, $recipient, $signed, $certificate);
            }
        });
    }

    /**
     * @param  array{type?: string, id?: string|null, ip?: string|null, user_agent?: string|null}  $actor
     */
    private function declineRecipient(SignatureEnvelope $envelope, SignatureRecipient $recipient, string $reason, array $actor): void
    {
        DB::transaction(function () use ($envelope, $recipient, $reason, $actor): void {
            $recipient->forceFill(['status' => RecipientStatus::Declined, 'declined_at' => now(), 'decline_reason' => $reason])->save();
            $envelope->recipients()->whereNotIn('status', [RecipientStatus::Signed->value, RecipientStatus::Declined->value])->update(['status' => RecipientStatus::Cancelled->value]);
            $envelope->forceFill(['status' => EnvelopeStatus::Declined])->save();
            $this->audit->record($envelope, AuditEvent::RecipientDeclined, $actor, $recipient, ['recipient_name' => $recipient->full_name, 'reason' => $reason]);
            $this->audit->record($envelope, AuditEvent::EnvelopeDeclined, ['type' => 'system'], $recipient);
        });

        $this->cancelAtProvider($envelope);
        $this->safely(fn () => $this->notifier->sendDeclined($envelope, $recipient));
        SignatureEnvelopeDeclined::dispatch($envelope);
    }

    private function cancelAtProvider(SignatureEnvelope $envelope): void
    {
        $this->safely(function () use ($envelope): void {
            $this->providers->provider($envelope->provider)->cancel($envelope, $this->config($envelope));
        });
    }

    /**
     * The stamps the platform draws for the signers that have a box.
     *
     * @return list<array{box: array{page: int, x: float, y: float, width: float, height: float}, lines: list<string>, qr: string|null, image: string|null}>
     */
    private function stamps(SignatureEnvelope $envelope): array
    {
        if ($envelope->document_format !== 'pades') {
            return [];
        }

        $stamps = [];

        foreach ($envelope->actingRecipients()->where('status', RecipientStatus::Signed->value)->get() as $recipient) {
            if ($recipient->signature_box === null) {
                continue;
            }

            $stamps[] = [
                'box' => $recipient->signature_box,
                'lines' => $this->stampLines($envelope, $recipient),
                'qr' => $this->notifier->verificationLink($envelope),
                'image' => $this->drawnSignature($recipient),
            ];
        }

        return $stamps;
    }

    /**
     * @return list<string>
     */
    private function stampLines(SignatureEnvelope $envelope, SignatureRecipient $recipient): array
    {
        $t = fn (string $key, array $replace = []): string => $this->texts->text($key, $envelope->locale, $replace, $envelope->tenant_id);

        return array_values(array_filter([
            $recipient->full_name,
            $t('signatures.stamp.level', ['level' => $envelope->required_level->value, 'jurisdiction' => $envelope->jurisdiction->value]),
            $t('signatures.stamp.signedAt', ['date' => ($recipient->signed_at ?? now())->copy()->utc()->format('d.m.Y H:i').' UTC']),
            $recipient->certificate_serial !== null ? $t('signatures.stamp.certificate', ['serial' => $recipient->certificate_serial]) : null,
            $t('signatures.stamp.verify', ['code' => $envelope->verification_code]),
        ]));
    }

    private function appearance(SignatureEnvelope $envelope, SignatureRecipient $recipient): SignatureAppearance
    {
        $now = new DateTimeImmutable;

        return new SignatureAppearance(
            $recipient->full_name,
            $now,
            $this->texts->text('signatures.stamp.reason', $envelope->locale, ['title' => $envelope->title], $envelope->tenant_id),
            null,
            $recipient->signature_box,
            $this->stampLines($envelope, $recipient),
        );
    }

    private function drawnSignature(SignatureRecipient $recipient): ?string
    {
        $data = $recipient->signature_data;

        if (! is_string($data) || ! str_starts_with($data, 'data:image/png;base64,')) {
            return null;
        }

        $binary = base64_decode(substr($data, strlen('data:image/png;base64,')), true);

        return $binary === false ? null : $binary;
    }

    /**
     * Keeps a drawn signature (PNG data URI) or the typed name; nothing else is stored.
     */
    private function storedSignatureData(?string $data): ?string
    {
        if ($data === null || $data === '') {
            return null;
        }

        return strlen($data) <= 400_000 ? $data : null;
    }

    private function assertCanAct(SignatureRecipient $recipient): SignatureEnvelope
    {
        $envelope = $recipient->envelope;

        if ($this->expireIfDue($envelope) || $envelope->refresh()->status === EnvelopeStatus::Expired) {
            throw new SignatureException('envelope_expired', 410);
        }

        if (! $envelope->status->isOpen()) {
            throw new SignatureException('envelope_closed', 410);
        }

        if ($recipient->status === RecipientStatus::Signed) {
            throw new SignatureException('already_signed', 409);
        }

        if (! $recipient->status->canAct()) {
            throw new SignatureException('not_your_turn', 409);
        }

        return $envelope;
    }

    private function config(SignatureEnvelope $envelope): SignatureProviderConfig
    {
        return $envelope->providerConfig ?? SignatureProviderConfig::withTrashed()->find($envelope->provider_config_id) ?? $this->providers->nativeConfig();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{type: string, id: string, ip: string|null, user_agent: string|null}
     */
    private function actor(SignatureRecipient $recipient, ?string $ip, ?string $userAgent, array $extra = []): array
    {
        return ['type' => 'recipient', 'id' => $recipient->id, 'ip' => $ip, 'user_agent' => $userAgent] + $extra;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(SignatureEnvelope $envelope, callable $callback): mixed
    {
        $lock = Cache::lock('esig:envelope:'.$envelope->id, 120);

        try {
            $lock->block(20);
        } catch (LockTimeoutException) {
            throw new SignatureException('envelope_busy', 409);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * A notification that cannot be sent must not undo a signature that was already applied.
     *
     * @param  callable(): mixed  $callback
     */
    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::warning('electronic-signature.side-effect.failed', ['error' => $exception->getMessage()]);
        }
    }
}
