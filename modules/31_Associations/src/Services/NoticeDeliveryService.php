<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Illuminate\Support\Collection;
use Modules\Associations\Enums\DeliveryChannel;
use Modules\Associations\Enums\DeliveryMode;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\AssociationDelivery;
use Modules\Associations\Models\Member;
use Modules\Communication\DTOs\DeliveryMessage;
use Modules\Communication\Exceptions\DeliveryFailedException;
use Modules\Communication\Models\CommunicationChannel;
use Modules\Communication\Models\DeliveryLog;
use Modules\Communication\Services\DocumentSender;

/**
 * Delivers notices and minutes to the people of the registry through the channels of the communication module, and
 * keeps one row per recipient and attempt with date, time, channel and outcome. A minor is reached through the guardian.
 */
final class NoticeDeliveryService
{
    /**
     * @param  Collection<int, Member>  $members  everybody the document concerns
     * @param  list<string>  $selectedMemberIds  used with the selected mode
     * @return list<AssociationDelivery>
     */
    public function deliver(
        string $subjectType,
        string $subjectId,
        Collection $members,
        DeliveryChannel $channel,
        DeliveryMode $mode,
        array $selectedMemberIds,
        string $subject,
        string $body,
        ?string $attachmentName,
        ?string $attachmentContent,
        ?string $userId,
    ): array {
        if ($channel === DeliveryChannel::Letter) {
            throw new AssociationException(__('association_governance.letter_is_printed'));
        }

        $settings = CommunicationChannel::query()->where('channel', $channel->value)->first();

        if ($settings !== null && ! $settings->is_enabled) {
            throw new AssociationException(__('association_governance.channel_disabled'));
        }

        $drivers = DocumentSender::driversOf($channel->value);
        $driver = app($drivers[$settings->driver ?? ($channel === DeliveryChannel::Email ? 'mail' : 'local')]);
        $results = [];

        foreach ($this->recipients($subjectId, $members, $mode, $selectedMemberIds) as $member) {
            $recipient = $this->address($member, $channel);
            $row = [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'member_id' => $member->id,
                'channel' => $channel,
                'recipient' => $recipient,
                'driver' => $driver->name(),
                'sent_by' => $userId,
                'attempted_at' => now(),
            ];

            if ($recipient === null || $recipient === '') {
                $results[] = AssociationDelivery::create([...$row, 'status' => AssociationDelivery::NoRecipient]);

                continue;
            }

            $withFile = $channel === DeliveryChannel::Email;
            $message = new DeliveryMessage($channel->value, $recipient, $subject, $body, $withFile ? $attachmentName : null, $withFile ? $attachmentContent : null);

            try {
                $outcome = $driver->send($message, $settings->credentials ?? []);
                $results[] = AssociationDelivery::create([...$row, 'status' => $outcome['status'], 'provider_reference' => $outcome['reference']]);
            } catch (DeliveryFailedException $exception) {
                $results[] = AssociationDelivery::create([...$row, 'status' => AssociationDelivery::Failed, 'error' => mb_substr($exception->getMessage(), 0, 1000)]);
            }

            DeliveryLog::create([
                'channel' => $channel->value,
                'driver' => $driver->name(),
                'document_type' => mb_substr('assoc_'.$subjectType, 0, 30),
                'document_id' => $subjectId,
                'recipient' => '#'.$member->id,
                'status' => end($results)->status,
                'sent_by' => $userId,
            ]);
        }

        return $results;
    }

    /**
     * Records that a printed letter was produced for each person.
     *
     * @param  Collection<int, Member>  $members
     */
    public function recordLetters(string $subjectType, string $subjectId, Collection $members, ?string $userId): void
    {
        foreach ($members as $member) {
            AssociationDelivery::create([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'member_id' => $member->id,
                'channel' => DeliveryChannel::Letter,
                'status' => AssociationDelivery::Printed,
                'sent_by' => $userId,
                'attempted_at' => now(),
            ]);
        }
    }

    /**
     * @return list<string> ids of the people already reached for a document
     */
    public function reachedMemberIds(string $subjectId): array
    {
        return AssociationDelivery::query()
            ->where('subject_id', $subjectId)
            ->whereIn('status', AssociationDelivery::Reached)
            ->distinct()
            ->pluck('member_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * @param  Collection<int, Member>  $members
     * @param  list<string>  $selectedMemberIds
     * @return Collection<int, Member>
     */
    private function recipients(string $subjectId, Collection $members, DeliveryMode $mode, array $selectedMemberIds): Collection
    {
        return match ($mode) {
            DeliveryMode::All => $members,
            DeliveryMode::Unreached => $members->reject(fn (Member $member): bool => in_array($member->id, $this->reachedMemberIds($subjectId), true))->values(),
            DeliveryMode::Selected => $members->filter(static fn (Member $member): bool => in_array($member->id, $selectedMemberIds, true))->values(),
        };
    }

    private function address(Member $member, DeliveryChannel $channel): ?string
    {
        $contact = $member->contact;

        if ($member->isMinor()) {
            $contact = $member->guardianships()->active()->orderByDesc('is_primary')->first()?->guardian ?? $contact;
        }

        return match ($channel) {
            DeliveryChannel::Email => $contact?->email,
            DeliveryChannel::Telegram => null,
            default => $contact?->phone,
        };
    }
}
