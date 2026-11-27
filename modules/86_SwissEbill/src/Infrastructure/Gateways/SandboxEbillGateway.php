<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Infrastructure\Gateways;

use Carbon\CarbonImmutable;
use DOMDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\SwissEbill\Application\Dto\GatewayConnectionResult;
use Modules\SwissEbill\Application\Dto\GatewayNotification;
use Modules\SwissEbill\Application\Dto\GatewayReceipt;
use Modules\SwissEbill\Application\Dto\GatewaySubmission;
use Modules\SwissEbill\Application\Dto\ParticipantLookupCriteria;
use Modules\SwissEbill\Application\Dto\ParticipantRecord;
use Modules\SwissEbill\Application\Ports\EbillGatewayInterface;
use Modules\SwissEbill\Domain\Enums\EbillChannelType;
use Modules\SwissEbill\Domain\Enums\EbillNotificationType;
use Modules\SwissEbill\Domain\Enums\EbillParticipantStatus;
use Modules\SwissEbill\Domain\Exceptions\EbillGatewayException;
use Modules\SwissEbill\Domain\Support\BillerIdValidator;
use Modules\SwissEbill\Models\EbillSetting;

/**
 * Deterministic simulator of the SIX Paynet network for development, demos and the test suite: no outbound call is made.
 *
 * Directory rules (the last digit decides, so a scenario can be reproduced from the data alone):
 *  - UID and IBAN: an odd last digit is an enrolled participant (UID: B2B, IBAN: B2C), an even one is not enrolled.
 *  - email: "optin" in the local part is pending opt-in, "suspended" is suspended, "ebill" is an enrolled B2C user.
 *  - participant id: any id with a valid Biller ID checksum is an enrolled B2B participant.
 * Transaction scenarios (last digit of the recipient id): 9 = the debtor rejects, 8 = stays in the e-banking, else approved.
 * Timeline after the submission: delivered after 1 minute, approved or rejected after 3 minutes, settled never (the camt.054 does it).
 */
class SandboxEbillGateway implements EbillGatewayInterface
{
    public const string PREFIX = 'ebill-sandbox:';

    public function testConnection(EbillSetting $setting): GatewayConnectionResult
    {
        return new GatewayConnectionResult(true, true, 12, 'Sandbox simulator ready', 'sandbox');
    }

    public function lookupParticipant(EbillSetting $setting, ParticipantLookupCriteria $criteria): ?ParticipantRecord
    {
        $digits = (string) preg_replace('/\D/', '', $criteria->value);
        $odd = $digits !== '' && ((int) substr($digits, -1)) % 2 === 1;

        return match ($criteria->type) {
            'uid' => $odd ? new ParticipantRecord(self::participantIdFor($criteria->value), EbillParticipantStatus::Enrolled, EbillChannelType::B2bEnterprise, 'Sandbox AG', $criteria->value) : null,
            'iban' => $odd ? new ParticipantRecord(self::participantIdFor($criteria->value), EbillParticipantStatus::Enrolled, EbillChannelType::B2cPrivate, 'Sandbox Private') : null,
            'participant_id' => BillerIdValidator::isValid($criteria->value) ? new ParticipantRecord($criteria->value, EbillParticipantStatus::Enrolled, EbillChannelType::B2bEnterprise, 'Sandbox Participant') : null,
            'email' => $this->byEmail($criteria->value),
            default => null,
        };
    }

    public function submit(EbillSetting $setting, GatewaySubmission $submission): GatewayReceipt
    {
        $known = Cache::get(self::PREFIX.'idem:'.$submission->idempotencyKey);

        if (is_array($known)) {
            return new GatewayReceipt($known['id'], CarbonImmutable::parse($known['submitted_at']));
        }

        $document = new DOMDocument;

        if (! @$document->loadXML($submission->xml)) {
            throw new EbillGatewayException('The sandbox rejected a payload that is not well-formed XML.', 400, 'schema_rejected');
        }

        if (! BillerIdValidator::isValid($submission->billerId)) {
            throw new EbillGatewayException('The sandbox rejected an unknown biller id.', 403, 'unknown_biller');
        }

        $transactionId = 'SBX-'.strtoupper(Str::random(12));
        $submittedAt = CarbonImmutable::now();
        $meta = ['id' => $transactionId, 'submitted_at' => $submittedAt->toIso8601String(), 'recipient' => $submission->recipientId, 'recalled' => false];

        Cache::put(self::PREFIX.'idem:'.$submission->idempotencyKey, $meta, now()->addDays(30));
        Cache::put(self::PREFIX.'txn:'.$transactionId, $meta, now()->addDays(30));

        return new GatewayReceipt($transactionId, $submittedAt);
    }

    public function recall(EbillSetting $setting, string $sixTransactionId): void
    {
        $meta = $this->meta($sixTransactionId);
        $approved = collect($this->events($meta))->contains(fn (GatewayNotification $n): bool => in_array($n->type, [EbillNotificationType::Approved, EbillNotificationType::Rejected], true));

        if ($approved) {
            throw new EbillGatewayException('The debtor already decided on this invoice.', 409, 'already_decided');
        }

        $meta['recalled'] = true;
        $meta['recalled_at'] = CarbonImmutable::now()->toIso8601String();
        Cache::put(self::PREFIX.'txn:'.$sixTransactionId, $meta, now()->addDays(30));
    }

    public function fetchStatus(EbillSetting $setting, string $sixTransactionId): array
    {
        return $this->events($this->meta($sixTransactionId));
    }

    /**
     * A valid, stable 17-digit participant id derived from a seed (the sandbox directory has no storage).
     */
    public static function participantIdFor(string $seed): string
    {
        $digits = substr((string) preg_replace('/\D/', '', hash('sha256', $seed)), 0, 14);
        $body = '41'.str_pad($digits, 14, '0');

        return $body.BillerIdValidator::checkDigit($body);
    }

    private function byEmail(string $email): ?ParticipantRecord
    {
        $local = strtolower(strstr($email, '@', true) ?: $email);
        $status = match (true) {
            str_contains($local, 'suspended') => EbillParticipantStatus::Suspended,
            str_contains($local, 'optin') => EbillParticipantStatus::PendingOptIn,
            str_contains($local, 'ebill') => EbillParticipantStatus::Enrolled,
            default => null,
        };

        return $status === null ? null : new ParticipantRecord(self::participantIdFor($email), $status, EbillChannelType::B2cPrivate, 'Sandbox User', null, $email);
    }

    /**
     * @return array{id: string, submitted_at: string, recipient: string, recalled: bool, recalled_at?: string}
     */
    private function meta(string $transactionId): array
    {
        $meta = Cache::get(self::PREFIX.'txn:'.$transactionId);

        if (! is_array($meta)) {
            throw new EbillGatewayException("Unknown sandbox transaction [{$transactionId}].", 404, 'unknown_transaction');
        }

        return $meta;
    }

    /**
     * @param  array{id: string, submitted_at: string, recipient: string, recalled: bool, recalled_at?: string}  $meta
     * @return list<GatewayNotification>
     */
    private function events(array $meta): array
    {
        $submitted = CarbonImmutable::parse($meta['submitted_at']);
        $elapsed = $submitted->diffInSeconds(now(), false);
        $last = (int) substr($meta['recipient'], -1);
        $events = [];

        if ($meta['recalled']) {
            return [new GatewayNotification('sbx-'.$meta['id'].'-cancelled', EbillNotificationType::Cancelled, $meta['id'], CarbonImmutable::parse($meta['recalled_at'] ?? $meta['submitted_at']))];
        }

        if ($elapsed >= 60) {
            $events[] = new GatewayNotification('sbx-'.$meta['id'].'-delivered', EbillNotificationType::Delivered, $meta['id'], $submitted->addMinute());
        }

        if ($elapsed >= 180 && $last !== 8) {
            $events[] = $last === 9
                ? new GatewayNotification('sbx-'.$meta['id'].'-rejected', EbillNotificationType::Rejected, $meta['id'], $submitted->addMinutes(3), null, 'AMOUNT_MISMATCH', 'The amount does not match the order.')
                : new GatewayNotification('sbx-'.$meta['id'].'-approved', EbillNotificationType::Approved, $meta['id'], $submitted->addMinutes(3), $submitted->addDays(5)->startOfDay());
        }

        return $events;
    }
}
