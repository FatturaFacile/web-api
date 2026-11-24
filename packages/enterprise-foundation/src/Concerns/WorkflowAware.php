<?php

declare(strict_types=1);

namespace Enterprise\Foundation\Concerns;

trait WorkflowAware
{
    public function getWorkflowInstanceId(): ?string
    {
        return $this->workflow_instance_id ?? null;
    }

    public function setWorkflowInstanceId(string $instanceId): void
    {
        $this->workflow_instance_id = $instanceId;
        $this->saveQuietly();
    }
}
