<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Enums;

/**
 * Events of the signature audit trail. The value is the translation suffix of `signatures.audit.<value>`.
 */
enum AuditEvent: string
{
    case EnvelopeCreated = 'envelope_created';
    case EnvelopeSent = 'envelope_sent';
    case DocumentViewed = 'document_viewed';
    case ChallengeSent = 'challenge_sent';
    case ChallengeFailed = 'challenge_failed';
    case ConsentGiven = 'consent_given';
    case IdentityVerified = 'identity_verified';
    case RecipientSigned = 'recipient_signed';
    case RecipientDeclined = 'recipient_declined';
    case ReminderSent = 'reminder_sent';
    case ProviderCallback = 'provider_callback';
    case TimestampApplied = 'timestamp_applied';
    case TimestampUnavailable = 'timestamp_unavailable';
    case EnvelopeSealed = 'envelope_sealed';
    case EnvelopeCompleted = 'envelope_completed';
    case EnvelopeDeclined = 'envelope_declined';
    case EnvelopeExpired = 'envelope_expired';
    case EnvelopeCancelled = 'envelope_cancelled';
    case DocumentDownloaded = 'document_downloaded';
    case DocumentArchived = 'document_archived';
}
