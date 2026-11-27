<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Services;

use App\Models\Contact;
use Modules\SwissEbill\Application\Dto\ParticipantLookupCriteria;
use Modules\SwissEbill\Application\Dto\ParticipantRecord;
use Modules\SwissEbill\Domain\Enums\EbillParticipantStatus;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Domain\Exceptions\EbillGatewayException;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;
use Modules\SwissEbill\Domain\Support\SwissUid;
use Modules\SwissEbill\Infrastructure\Gateways\EbillGatewayFactory;
use Modules\SwissEbill\Models\EbillParticipantLookup;
use Modules\SwissEbill\Models\EbillSetting;

/**
 * The participant directory of SIX Paynet / PostFinance: cached lookups by UID, email, IBAN or participant id, and the
 * synchronisation of the eBill columns of a customer.
 */
final class ParticipantDirectoryService
{
    public function __construct(
        private readonly EbillSettingRepositoryInterface $settings,
        private readonly EbillGatewayFactory $gateways,
    ) {}

    /**
     * @throws EbillException when eBill is not configured
     * @throws EbillGatewayException when the directory cannot be asked
     */
    public function lookup(ParticipantLookupCriteria $criteria, bool $forceRefresh = false): ?ParticipantRecord
    {
        $setting = $this->requireSetting();

        $cached = EbillParticipantLookup::query()
            ->where('lookup_type', $criteria->type)
            ->where('lookup_key_hash', $criteria->cacheKey())
            ->first();

        if (! $forceRefresh && $cached !== null && $cached->isFresh()) {
            return $cached->found && is_array($cached->result) ? ParticipantRecord::fromArray($cached->result) : null;
        }

        $record = $this->gateways->for($setting)->lookupParticipant($setting, $criteria);
        $ttl = max(1, (int) config('swiss_ebill.lookup_cache_hours', 24));

        EbillParticipantLookup::query()->updateOrCreate(
            ['lookup_type' => $criteria->type, 'lookup_key_hash' => $criteria->cacheKey()],
            ['found' => $record !== null, 'result' => $record?->toArray(), 'fetched_at' => now(), 'expires_at' => now()->addHours($ttl)],
        );

        return $record;
    }

    /**
     * Looks the customer up by what it has, in order: the stored participant id, the UID, the email, the (QR-)IBAN. The first
     * hit wins; no hit marks the customer as not enrolled. A gateway failure is thrown, never recorded as "not enrolled".
     *
     * @return array{contact: Contact, record: ParticipantRecord|null}
     */
    public function syncCustomer(Contact $contact, bool $forceRefresh = false): array
    {
        $record = null;

        foreach ($this->criteriaFor($contact) as $criteria) {
            $record = $this->lookup($criteria, $forceRefresh);

            if ($record !== null) {
                break;
            }
        }

        $contact->forceFill($record === null
            ? ['ebill_recipient_id' => null, 'ebill_status' => EbillParticipantStatus::NotEnrolled->value, 'ebill_channel_type' => null, 'ebill_verified_at' => now()]
            : $this->columns($record))->save();

        return ['contact' => $contact, 'record' => $record];
    }

    /**
     * Stores a directory entry on the customer (the one-click link of the lookup dialog).
     */
    public function link(Contact $contact, ParticipantRecord $record): Contact
    {
        $contact->forceFill($this->columns($record))->save();

        return $contact;
    }

    /**
     * @return list<ParticipantLookupCriteria>
     */
    public function criteriaFor(Contact $contact): array
    {
        $criteria = [];

        if (trim((string) $contact->ebill_recipient_id) !== '') {
            $criteria[] = ParticipantLookupCriteria::participantId((string) $contact->ebill_recipient_id);
        }

        if (SwissUid::isValid($contact->uid_number)) {
            $criteria[] = ParticipantLookupCriteria::uid((string) $contact->uid_number);
        }

        if (trim((string) $contact->email) !== '') {
            $criteria[] = ParticipantLookupCriteria::email((string) $contact->email);
        }

        foreach ([$contact->qr_iban, $contact->iban] as $iban) {
            if (is_string($iban) && trim($iban) !== '') {
                $criteria[] = ParticipantLookupCriteria::iban($iban);
            }
        }

        return $criteria;
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(ParticipantRecord $record): array
    {
        return [
            'ebill_recipient_id' => $record->participantId,
            'ebill_status' => $record->status->value,
            'ebill_channel_type' => $record->channelType?->value,
            'ebill_verified_at' => now(),
        ];
    }

    private function requireSetting(): EbillSetting
    {
        $setting = $this->settings->current();

        if ($setting === null || ! $setting->ebill_enabled) {
            throw new EbillException('ebill_disabled', 'Swiss eBill is not enabled for this company.', 422);
        }

        return $setting;
    }
}
