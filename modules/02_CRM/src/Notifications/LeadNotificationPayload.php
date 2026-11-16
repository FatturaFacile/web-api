<?php

declare(strict_types=1);

namespace Modules\CRM\Notifications;

use Modules\CRM\Models\Lead;

/**
 * Scalar snapshot of a lead carried by queued notifications. Models are not serialized on purpose: a queue
 * worker has no tenant context, so a tenant-scoped Lead could not be restored there.
 */
final readonly class LeadNotificationPayload
{
    public function __construct(
        public string $leadId,
        public string $label,
        public ?string $rating,
        public int $score,
    ) {}

    public static function fromLead(Lead $lead): self
    {
        return new self(
            leadId: (string) $lead->id,
            label: $lead->displayLabel(),
            rating: $lead->rating !== null ? (string) $lead->rating : null,
            score: (int) $lead->score,
        );
    }

    public function url(): string
    {
        return rtrim((string) config('crm.frontend_url', config('app.url')), '/').'/crm/leads/'.$this->leadId;
    }

    /**
     * Stable payload shared by the database channel and, later, mobile push.
     *
     * @return array{lead_id: string, title: string, rating: ?string, score: int, url: string}
     */
    public function toArray(string $title): array
    {
        return [
            'lead_id' => $this->leadId,
            'title' => $title,
            'rating' => $this->rating,
            'score' => $this->score,
            'url' => $this->url(),
        ];
    }
}
