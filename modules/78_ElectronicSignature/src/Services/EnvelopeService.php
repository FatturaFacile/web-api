<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use App\Models\Translation;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ElectronicSignature\DTOs\SignableDocument;
use Modules\ElectronicSignature\Enums\AuditEvent;
use Modules\ElectronicSignature\Enums\AuthMethod;
use Modules\ElectronicSignature\Enums\EnvelopeStatus;
use Modules\ElectronicSignature\Enums\Jurisdiction;
use Modules\ElectronicSignature\Enums\ProviderType;
use Modules\ElectronicSignature\Enums\RecipientRole;
use Modules\ElectronicSignature\Enums\RecipientStatus;
use Modules\ElectronicSignature\Enums\RoutingMode;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Enums\SignatureLevel;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Models\SignatureTemplate;
use Modules\ElectronicSignature\Sources\DocumentSignableSource;

/**
 * Creates, sends, reminds and cancels signature envelopes, and runs the scheduled lifecycle (reminders, expiry, pending
 * signatures). The rules of the compliance policy are enforced here, not in the UI: a document cannot be sent for a tier
 * lower than its policy requires.
 */
final class EnvelopeService
{
    public function __construct(
        private readonly SignableDocumentRegistry $documents,
        private readonly ProviderRegistry $providers,
        private readonly SignaturePolicyResolver $policy,
        private readonly PdfSignatureService $pdf,
        private readonly EnvelopeStorage $storage,
        private readonly SignatureAuditService $audit,
        private readonly SigningService $signing,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Creates a draft envelope.
     *
     * @param  array<string, mixed>  $data  validated by CreateEnvelopeRequest
     *
     * @throws SignatureException
     */
    public function create(array $data, ?UploadedFile $upload, User $user, ?string $ip, ?string $userAgent): SignatureEnvelope
    {
        $type = SignableType::from((string) $data['signable_type']);
        $level = SignatureLevel::from((string) $data['required_level']);
        $jurisdiction = Jurisdiction::from((string) $data['jurisdiction']);
        $template = isset($data['template_id']) ? SignatureTemplate::query()->find($data['template_id']) : null;
        $routing = RoutingMode::from((string) ($data['routing_mode'] ?? $template?->routing_mode->value ?? RoutingMode::Sequential->value));
        $locale = Translation::normalizeLocale((string) ($data['locale'] ?? app()->getLocale()));

        $document = $this->documentOf($type, $data, $upload, $locale);
        $this->assertNoOpenEnvelope($type, $data['signable_id'] ?? null);

        $minimum = $this->policy->minimumLevel($type->value, $document->amount);

        if (! $level->satisfies($minimum)) {
            throw new SignatureException('level_below_policy', 422, ['required' => $minimum->value]);
        }

        $config = $this->providers->configFor($data['provider_config_id'] ?? $template?->provider_config_id, $level, $jurisdiction);
        $provider = $this->providers->provider($config->provider);

        if ($provider->requiresSequentialRouting() && $routing === RoutingMode::Parallel) {
            throw new SignatureException('parallel_not_supported_by_provider');
        }

        if ($document->format !== 'pades' && ! in_array($config->provider, [ProviderType::Native, ProviderType::InfoCert], true)) {
            throw new SignatureException('format_not_supported');
        }

        $recipients = $this->normaliseRecipients((array) $data['recipients'], $level, $routing, $document->format);
        $content = $document->format === 'pades' ? $this->pdf->stamp($document->pdf) : $document->pdf;

        if (strlen($content) > (int) config('electronic_signature.max_document_bytes')) {
            throw new SignatureException('document_too_large', 413);
        }

        $days = (int) ($data['expiration_days'] ?? $template->expiration_days ?? config('electronic_signature.default_expiration_days'));
        $days = max(1, min($days, (int) config('electronic_signature.max_expiration_days')));
        $id = (string) Str::uuid7();
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? throw new SignatureException('tenant_missing', 403));
        $path = 'signatures/'.$tenantId.'/'.$id.'/original.'.($document->format === 'cades' ? 'bin' : ($document->format === 'xades' ? 'xml' : 'pdf'));
        $this->storage->put($path, $content);

        try {
            return DB::transaction(function () use ($id, $type, $data, $level, $jurisdiction, $routing, $locale, $document, $config, $path, $content, $days, $template, $recipients, $user, $ip, $userAgent): SignatureEnvelope {
                $envelope = SignatureEnvelope::query()->create([
                    'id' => $id,
                    'signable_type' => $type->value,
                    'signable_id' => $data['signable_id'] ?? null,
                    'verification_code' => $this->verificationCode(),
                    'title' => (string) ($data['title'] ?? $document->title),
                    'subject' => $data['subject'] ?? null,
                    'message' => $data['message'] ?? null,
                    'required_level' => $level,
                    'jurisdiction' => $jurisdiction,
                    'routing_mode' => $routing,
                    'status' => EnvelopeStatus::Draft,
                    'provider_config_id' => $config->id,
                    'provider' => $config->provider,
                    'locale' => $locale,
                    'document_format' => $document->format,
                    'file_name' => $document->fileName,
                    'original_path' => $path,
                    'original_sha256' => $this->pdf->sha256($content),
                    'expires_at' => isset($data['expires_at']) ? $data['expires_at'] : now()->addDays($days),
                    'metadata' => [
                        'reminder_days' => array_values((array) ($data['reminder_days'] ?? $template->reminder_days ?? config('electronic_signature.default_reminder_days'))),
                        'amount' => $document->amount,
                        'currency' => $document->currency,
                        'template_id' => $template?->id,
                        'sandbox' => $config->is_sandbox,
                    ],
                    'created_by' => $user->id,
                ]);

                foreach ($recipients as $recipient) {
                    $envelope->recipients()->create($recipient + ['status' => RecipientStatus::Waiting]);
                }

                $this->audit->record($envelope, AuditEvent::EnvelopeCreated, ['type' => 'user', 'id' => (string) $user->id, 'ip' => $ip, 'user_agent' => $userAgent], null, [
                    'level' => $level->value,
                    'jurisdiction' => $jurisdiction->value,
                    'provider' => $config->provider->value,
                    'routing' => $routing->value,
                    'recipients' => count($recipients),
                ], $envelope->original_sha256);

                return $envelope->load('recipients');
            });
        } catch (\Throwable $exception) {
            $this->storage->delete($path);

            throw $exception;
        }
    }

    /**
     * The document as it would be signed, for the placement of the signature boxes before the envelope exists.
     *
     * @throws SignatureException
     */
    public function preview(SignableType $type, string $id, string $locale): SignableDocument
    {
        if ($type === SignableType::Upload) {
            throw new SignatureException('document_not_found', 404);
        }

        return $this->documentOf($type, ['signable_id' => $id], null, Translation::normalizeLocale($locale));
    }

    /**
     * Sends a draft: opens the envelope at the provider and gives the link to the first recipients.
     */
    public function send(SignatureEnvelope $envelope, User $user, ?string $ip, ?string $userAgent): SignatureEnvelope
    {
        if ($envelope->status !== EnvelopeStatus::Draft) {
            throw new SignatureException('envelope_not_draft', 409);
        }

        if ($envelope->expires_at === null || $envelope->expires_at->isPast()) {
            throw new SignatureException('expiry_in_past');
        }

        $config = $envelope->providerConfig ?? throw new SignatureException('provider_not_found', 404);
        $externalId = $this->providers->provider($envelope->provider)->openEnvelope($envelope, $config, $this->storage->original($envelope));

        DB::transaction(function () use ($envelope, $externalId, $user, $ip, $userAgent): void {
            $envelope->forceFill([
                'status' => EnvelopeStatus::Pending,
                'sent_at' => now(),
                'external_id' => $externalId,
                'working_path' => $envelope->original_path,
            ])->save();
            $this->audit->record($envelope, AuditEvent::EnvelopeSent, ['type' => 'user', 'id' => (string) $user->id, 'ip' => $ip, 'user_agent' => $userAgent], null, ['external_id' => $externalId], $envelope->original_sha256);
        });

        $this->signing->startRouting($envelope);

        return $envelope->refresh()->load('recipients');
    }

    /**
     * Sends the personal links again to the recipients whose turn it is.
     */
    public function remind(SignatureEnvelope $envelope, User $user, ?string $ip): int
    {
        if (! $envelope->status->isOpen()) {
            throw new SignatureException('envelope_closed', 409);
        }

        $count = $this->signing->remindPending($envelope, ['type' => 'user', 'id' => (string) $user->id, 'ip' => $ip]);

        if ($count === 0) {
            throw new SignatureException('nothing_to_remind', 409);
        }

        return $count;
    }

    /**
     * Voids an envelope (draft or open): the links stop working and the provider envelope is cancelled.
     */
    public function void(SignatureEnvelope $envelope, User $user, ?string $ip, ?string $reason): SignatureEnvelope
    {
        if ($envelope->status->isFinal()) {
            throw new SignatureException('envelope_closed', 409);
        }

        DB::transaction(function () use ($envelope, $user, $ip, $reason): void {
            $envelope->forceFill(['status' => EnvelopeStatus::Cancelled])->save();
            $envelope->recipients()->where('status', '!=', RecipientStatus::Signed->value)->update(['status' => RecipientStatus::Cancelled->value, 'access_token_hash' => null]);
            $this->audit->record($envelope, AuditEvent::EnvelopeCancelled, ['type' => 'user', 'id' => (string) $user->id, 'ip' => $ip], null, ['reason' => $reason]);
        });

        $config = $envelope->providerConfig;

        if ($config !== null) {
            try {
                $this->providers->provider($envelope->provider)->cancel($envelope, $config);
            } catch (SignatureException) {
                // The envelope is void here whatever the provider answers; its own expiry closes the remote copy.
            }
        }

        return $envelope->refresh();
    }

    /**
     * Scheduled run of a company: expires the overdue envelopes, sends the reminders (3 and 1 day before the expiry by
     * default) and collects the signatures that were pending at the provider.
     *
     * @return array{expired: int, reminded: int}
     */
    public function processLifecycle(): array
    {
        $expired = 0;
        $reminded = 0;

        foreach (SignatureEnvelope::query()->whereIn('status', [EnvelopeStatus::Pending->value, EnvelopeStatus::PartiallySigned->value])->get() as $envelope) {
            if ($this->signing->expireIfDue($envelope)) {
                $expired++;

                continue;
            }

            $this->signing->pollPending($envelope);
            $reminded += $this->remindIfDue($envelope);
        }

        return ['expired' => $expired, 'reminded' => $reminded];
    }

    private function remindIfDue(SignatureEnvelope $envelope): int
    {
        if ($envelope->expires_at === null || ! $envelope->status->isOpen()) {
            return 0;
        }

        $daysLeft = (int) ceil(now()->diffInSeconds($envelope->expires_at, false) / 86400);
        $configured = array_map('intval', (array) ($envelope->metadata['reminder_days'] ?? config('electronic_signature.default_reminder_days')));
        $sent = array_map('intval', (array) ($envelope->reminders_sent ?? []));
        $due = array_values(array_filter($configured, static fn (int $day): bool => $day >= $daysLeft && ! in_array($day, $sent, true)));

        if ($due === [] || $daysLeft < 0) {
            return 0;
        }

        // One reminder, however many thresholds were crossed since the last run.
        $envelope->forceFill(['reminders_sent' => array_values(array_unique([...$sent, ...$due]))])->save();

        return $this->signing->remindPending($envelope, ['type' => 'system']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function documentOf(SignableType $type, array $data, ?UploadedFile $upload, string $locale): SignableDocument
    {
        if ($type === SignableType::Upload) {
            if ($upload === null) {
                throw new SignatureException('file_required');
            }

            $content = (string) file_get_contents((string) $upload->getRealPath());
            $name = $upload->getClientOriginalName();

            return new SignableDocument($content, pathinfo($name, PATHINFO_FILENAME), isset($data['amount']) ? (string) $data['amount'] : null, isset($data['currency']) ? (string) $data['currency'] : null, DocumentSignableSource::formatOf($name, $content), $name);
        }

        $source = $this->documents->source($type);
        $record = $source->find((string) ($data['signable_id'] ?? '')) ?? throw new SignatureException('document_not_found', 404);

        return $source->document($record, $locale);
    }

    private function assertNoOpenEnvelope(SignableType $type, mixed $signableId): void
    {
        if ($type === SignableType::Upload || $signableId === null) {
            return;
        }

        $open = SignatureEnvelope::query()
            ->where('signable_type', $type->value)
            ->where('signable_id', $signableId)
            ->whereIn('status', [EnvelopeStatus::Draft->value, EnvelopeStatus::Pending->value, EnvelopeStatus::PartiallySigned->value])
            ->exists();

        if ($open) {
            throw new SignatureException('envelope_already_open', 409);
        }
    }

    /**
     * Applies the rules of the recipients: at least one that acts, no duplicate address, a phone for the SMS code, a second
     * factor for the advanced and qualified tiers.
     *
     * @param  list<array<string, mixed>>  $recipients
     * @return list<array<string, mixed>>
     */
    private function normaliseRecipients(array $recipients, SignatureLevel $level, RoutingMode $routing, string $format): array
    {
        if (count($recipients) > (int) config('electronic_signature.max_recipients')) {
            throw new SignatureException('too_many_recipients');
        }

        $result = [];
        $emails = [];
        $acting = 0;

        foreach ($recipients as $index => $recipient) {
            $role = RecipientRole::from((string) ($recipient['role'] ?? RecipientRole::Signer->value));
            $method = AuthMethod::from((string) ($recipient['auth_method'] ?? AuthMethod::EmailLink->value));
            $email = mb_strtolower(trim((string) $recipient['email']));

            if (in_array($email, $emails, true)) {
                throw new SignatureException('duplicate_recipient');
            }

            $emails[] = $email;

            if ($role->acts()) {
                $acting++;

                if ($level !== SignatureLevel::Fes && $method === AuthMethod::EmailLink) {
                    throw new SignatureException('strong_authentication_required');
                }

                if ($method === AuthMethod::SmsOtp || $method === AuthMethod::MobileApp) {
                    if (($recipient['phone'] ?? '') === '') {
                        throw new SignatureException('phone_required');
                    }
                }
            }

            $box = $format === 'pades' && $role->acts() ? ($recipient['signature_box'] ?? null) : null;

            $result[] = [
                'signing_order' => $routing === RoutingMode::Parallel ? 1 : max(1, (int) ($recipient['signing_order'] ?? $index + 1)),
                'role' => $role,
                'full_name' => trim((string) $recipient['full_name']),
                'email' => $email,
                'phone' => $recipient['phone'] ?? null,
                'identity_metadata' => $recipient['identity_metadata'] ?? null,
                'auth_method' => $method,
                'signature_box' => $box,
            ];
        }

        if ($acting === 0) {
            throw new SignatureException('no_signer');
        }

        return $result;
    }

    private function verificationCode(): string
    {
        do {
            $code = implode('-', str_split(strtoupper(Str::random(20)), 5));
        } while (SignatureEnvelope::withoutGlobalScopes()->where('verification_code', $code)->exists());

        return $code;
    }
}
