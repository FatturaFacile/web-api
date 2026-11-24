<?php

declare(strict_types=1);

namespace Modules\CRM\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Real-time notice on the private tenant channel that the sales board changed (deal created, moved, edited or noted),
 * so every connected device refreshes. It carries identifiers and headline figures only, never notes or contacts.
 */
final class OpportunityBoardUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $opportunityId,
        public readonly string $change,
        public readonly ?string $pipelineId,
        public readonly ?string $stageId,
        public readonly ?string $actorUserId,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.crm-pipeline")];
    }

    public function broadcastAs(): string
    {
        return 'crm.opportunity.board-updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'opportunity_id' => $this->opportunityId,
            'change' => $this->change,
            'pipeline_id' => $this->pipelineId,
            'stage_id' => $this->stageId,
            'actor_user_id' => $this->actorUserId,
        ];
    }
}
