<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use Modules\ElectronicSignature\Models\SignatureAuditLog;
use Modules\ElectronicSignature\Models\SignatureEnvelope;

/**
 * The certificate of completion: a PDF dossier with the document hashes, the signers, the identity checks and the whole
 * audit trail with its hash chain. It travels with the signed document into the archive (conservazione a norma, GeBüV) and
 * is the evidence in a dispute.
 */
final class AuditCertificateService
{
    public function __construct(
        private readonly SimpleDocumentRenderer $renderer,
        private readonly SignatureTextResolver $texts,
        private readonly SignatureAuditService $audit,
        private readonly SignatureNotifier $notifier,
    ) {}

    public function render(SignatureEnvelope $envelope): string
    {
        $locale = $envelope->locale;
        $tenantId = $envelope->tenant_id;
        $t = fn (string $key, array $replace = []): string => $this->texts->text($key, $locale, $replace, $tenantId);
        $utc = static fn (?\DateTimeInterface $time): string => $time === null ? '—' : $time->format('Y-m-d H:i:s').' UTC';

        $facts = [
            [$t('signatures.cert.code'), $envelope->verification_code],
            [$t('signatures.cert.title'), $envelope->title],
            [$t('signatures.cert.level'), $t('signatures.level.'.$envelope->required_level->value)],
            [$t('signatures.cert.jurisdiction'), $t('signatures.jurisdiction.'.$envelope->jurisdiction->value)],
            [$t('signatures.cert.provider'), $t('signatures.provider.'.$envelope->provider->value)],
            [$t('signatures.cert.status'), $t('signatures.status.'.$envelope->status->value)],
            [$t('signatures.cert.created'), $utc($envelope->created_at)],
            [$t('signatures.cert.completed'), $utc($envelope->completed_at)],
            [$t('signatures.cert.originalHash'), (string) $envelope->original_sha256],
            [$t('signatures.cert.signedHash'), (string) $envelope->signed_sha256],
            [$t('signatures.cert.chain'), $this->audit->verifyChain($envelope) ? $t('signatures.cert.chainValid') : $t('signatures.cert.chainBroken')],
            [$t('signatures.cert.verifyAt'), $this->notifier->verificationLink($envelope)],
        ];

        $signers = [];

        foreach ($envelope->recipients as $recipient) {
            $signers[] = [
                $recipient->full_name,
                $recipient->email,
                $t('signatures.role.'.$recipient->role->value),
                $t('signatures.recipientStatus.'.$recipient->status->value),
                $utc($recipient->signed_at ?? $recipient->declined_at),
                (string) $recipient->signature_method,
            ];
        }

        $trail = [];

        /** @var SignatureAuditLog $entry */
        foreach ($envelope->auditLogs as $entry) {
            $trail[] = [
                (string) $entry->sequence,
                $t('signatures.audit.'.$entry->event),
                $utc($entry->occurred_at),
                (string) ($entry->metadata['recipient_name'] ?? $entry->actor_type),
                (string) $entry->ip_address,
                substr($entry->entry_hash, 0, 12),
            ];
        }

        return $this->renderer->render(
            $t('signatures.cert.heading'),
            $facts,
            [
                ['title' => $t('signatures.cert.signers'), 'headers' => [$t('signatures.field.name'), $t('signatures.field.email'), $t('signatures.field.role'), $t('signatures.field.status'), $t('signatures.cert.when'), $t('signatures.cert.method')], 'rows' => $signers, 'widths' => [95.0, 120.0, 55.0, 55.0, 110.0, 62.0]],
                ['title' => $t('signatures.cert.trail'), 'headers' => ['#', $t('signatures.cert.event'), $t('signatures.cert.when'), $t('signatures.cert.actor'), 'IP', 'Hash'], 'rows' => $trail, 'widths' => [24.0, 130.0, 110.0, 100.0, 80.0, 53.0]],
            ],
            [$t('signatures.cert.disclaimer')],
            $t('signatures.cert.footer'),
        );
    }
}
