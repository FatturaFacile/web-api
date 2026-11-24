<?php

declare(strict_types=1);

namespace Modules\CRM\DTOs;

final readonly class IntakeResult
{
    public const string CREATED = 'created';

    public const string MERGED = 'merged';

    public const string DUPLICATE_IGNORED = 'duplicate_ignored';

    public function __construct(
        public string $leadId,
        public string $status,
        public ?int $score = null,
        public ?string $rating = null,
        public ?string $assignedUserId = null,
    ) {}

    public function isCreated(): bool
    {
        return $this->status === self::CREATED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->isCreated()) {
            return [
                'lead_id' => $this->leadId,
                'status' => $this->status,
                'score' => $this->score,
                'rating' => $this->rating,
                'assigned_user_id' => $this->assignedUserId,
            ];
        }

        $payload = [
            'lead_id' => $this->leadId,
            'status' => $this->status,
            'deduplicated' => true,
        ];

        if ($this->status === self::MERGED) {
            $payload['merged_into'] = $this->leadId;
        }

        return $payload;
    }
}
