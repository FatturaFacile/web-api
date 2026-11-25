<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Modules\ElectronicSignature\DTOs\PdfSignatureInfo;
use Modules\ElectronicSignature\Enums\AuditEvent;
use Modules\ElectronicSignature\Enums\EnvelopeStatus;
use Modules\ElectronicSignature\Enums\SignatureLevel;
use Modules\ElectronicSignature\Models\SignatureAuditLog;
use Modules\ElectronicSignature\Models\SignatureEnvelope;

/**
 * Public verification of a signed document: by the verification code printed on it, by the SHA-256 of the file, or by the
 * file itself. It answers with what the platform recorded (status, signers, tier, time stamp, audit chain) and, for a PDF
 * that is handed in, with what the file proves by itself (every embedded signature checked against the bytes it covers).
 * Nothing personal beyond the names of the signers is disclosed: no e-mail address, phone, IP address or identity data.
 */
final class SignatureVerificationService
{
    public function __construct(
        private readonly PdfSignatureService $pdf,
        private readonly SignatureAuditService $audit,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function byCode(string $code): array
    {
        $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        $formatted = implode('-', str_split($normalised, 5));
        $envelope = strlen($normalised) === 20 ? SignatureEnvelope::withoutGlobalScopes()->where('verification_code', $formatted)->first() : null;

        return $envelope === null ? $this->notFound() : $this->describe($envelope, 'code', null);
    }

    /**
     * @return array<string, mixed>
     */
    public function byHash(string $sha256): array
    {
        return $this->lookupHash(strtolower($sha256)) ?? $this->notFound();
    }

    /**
     * @return array<string, mixed>
     */
    public function byFile(string $content): array
    {
        $hash = $this->pdf->sha256($content);
        $signatures = str_starts_with($content, '%PDF-') ? $this->pdf->inspect($content) : [];
        $found = $this->lookupHash($hash, $signatures);

        if ($found !== null) {
            return $found;
        }

        // Unknown to the platform: what the file proves by itself is still worth showing.
        return $this->notFound() + [
            'document_sha256' => $hash,
            'pdf_signatures' => array_map(static fn ($signature): array => $signature->toArray(), $signatures),
            'document_intact' => $signatures === [] ? null : ! in_array(false, array_map(static fn ($signature): bool => $signature->intact, $signatures), true),
        ];
    }

    /**
     * @param  list<PdfSignatureInfo>|null  $signatures
     * @return array<string, mixed>|null
     */
    private function lookupHash(string $hash, ?array $signatures = null): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return null;
        }

        $signed = SignatureEnvelope::withoutGlobalScopes()->where('signed_sha256', $hash)->first();

        if ($signed !== null) {
            return $this->describe($signed, 'signed', $signatures);
        }

        $original = SignatureEnvelope::withoutGlobalScopes()->where('original_sha256', $hash)->orderByDesc('created_at')->first();

        return $original === null ? null : $this->describe($original, 'original', $signatures);
    }

    /**
     * @param  list<PdfSignatureInfo>|null  $uploaded  signatures read from a file that was handed in
     * @return array<string, mixed>
     */
    private function describe(SignatureEnvelope $envelope, string $matched, ?array $uploaded): array
    {
        $tenant = Tenant::query()->find($envelope->tenant_id);

        if ($tenant instanceof Tenant) {
            $this->tenantContext->setTenant($tenant);
        }

        $envelope = SignatureEnvelope::query()->with(['recipients', 'auditLogs'])->find($envelope->id) ?? $envelope;
        $completed = $envelope->status === EnvelopeStatus::Completed;
        $signatures = $uploaded;

        if ($signatures === null && $completed && $envelope->signed_path !== null && $envelope->document_format === 'pades') {
            try {
                $signatures = $this->pdf->inspect(app(EnvelopeStorage::class)->signed($envelope));
            } catch (\Throwable) {
                $signatures = [];
            }
        }

        $signatures ??= [];
        $intact = $signatures === [] ? null : ! in_array(false, array_map(static fn ($signature): bool => $signature->intact, $signatures), true);
        $chainValid = $this->audit->verifyChain($envelope);
        $timestamp = $envelope->auditLogs->first(static fn (SignatureAuditLog $entry): bool => $entry->event === AuditEvent::TimestampApplied->value);

        return [
            'found' => true,
            'matched' => $matched,
            'code' => $envelope->verification_code,
            'title' => $envelope->title,
            'status' => $envelope->status->value,
            'level' => $envelope->required_level->value,
            'jurisdiction' => $envelope->jurisdiction->value,
            'provider' => $envelope->provider->value,
            'completed_at' => $envelope->completed_at?->toIso8601String(),
            'document_sha256' => $matched === 'signed' ? $envelope->signed_sha256 : $envelope->original_sha256,
            'signed_sha256' => $envelope->signed_sha256,
            'original_sha256' => $envelope->original_sha256,
            'audit_chain_valid' => $chainValid,
            'document_intact' => $intact,
            'assurance' => $this->assurance($envelope, $completed, $intact, $chainValid, $matched),
            'timestamp' => $timestamp === null ? null : [
                'provider' => $timestamp->receipt,
                'qualified' => (bool) ($timestamp->metadata['qualified'] ?? false),
                'issued_at' => $timestamp->metadata['issued_at'] ?? null,
            ],
            'signers' => $envelope->recipients->where('role', '!=', 'CC_RECIPIENT')->map(static fn ($recipient): array => [
                'name' => $recipient->full_name,
                'role' => $recipient->role->value,
                'status' => $recipient->status->value,
                'signed_at' => $recipient->signed_at?->toIso8601String(),
                'certificate_serial' => $recipient->certificate_serial,
                'method' => $recipient->signature_method,
            ])->values()->all(),
            'pdf_signatures' => array_map(static fn ($signature): array => $signature->toArray(), $signatures),
        ];
    }

    /**
     * What the verifier can rely on: `qualified` and `advanced` only for a completed envelope of a live (non-sandbox)
     * provider whose document and audit chain are intact; `simple` for the simple tier; `sandbox` for a test signature; `invalid`
     * when a check failed; `incomplete` while it is not signed by everyone.
     */
    private function assurance(SignatureEnvelope $envelope, bool $completed, ?bool $intact, bool $chainValid, string $matched): string
    {
        if ($intact === false || ! $chainValid) {
            return 'invalid';
        }

        if (! $completed || $matched !== 'signed') {
            return 'incomplete';
        }

        if (($envelope->metadata['sandbox'] ?? false) === true) {
            return 'sandbox';
        }

        return match ($envelope->required_level) {
            SignatureLevel::Feq => $envelope->provider->value === 'native' ? 'advanced' : 'qualified',
            SignatureLevel::Fea => 'advanced',
            SignatureLevel::Fes => 'simple',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function notFound(): array
    {
        return ['found' => false, 'assurance' => 'unknown'];
    }
}
