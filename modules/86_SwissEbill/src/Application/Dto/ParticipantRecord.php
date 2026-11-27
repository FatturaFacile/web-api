<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Dto;

use Modules\SwissEbill\Domain\Enums\EbillChannelType;
use Modules\SwissEbill\Domain\Enums\EbillParticipantStatus;

/**
 * A directory entry of the SIX Paynet / PostFinance participant directory.
 */
final readonly class ParticipantRecord
{
    public function __construct(
        public string $participantId,
        public EbillParticipantStatus $status,
        public ?EbillChannelType $channelType,
        public ?string $displayName = null,
        public ?string $uid = null,
        public ?string $email = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'participant_id' => $this->participantId,
            'status' => $this->status->value,
            'channel_type' => $this->channelType?->value,
            'display_name' => $this->displayName,
            'uid' => $this->uid,
            'email' => $this->email,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['participant_id'],
            EbillParticipantStatus::tryFrom((string) ($data['status'] ?? '')) ?? EbillParticipantStatus::Unknown,
            isset($data['channel_type']) ? EbillChannelType::tryFrom((string) $data['channel_type']) : null,
            $data['display_name'] ?? null,
            $data['uid'] ?? null,
            $data['email'] ?? null,
        );
    }
}
