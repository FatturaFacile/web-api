<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\Models\Project;

/**
 * Port to the Notification & Alert Engine (Mod 008): delivers one threshold breach of a project or WBS node to the
 * people who manage it. Returns the number of recipients reached; a stand-in can record the calls in tests.
 */
interface ProjectEvmAlertGatewayInterface
{
    /**
     * @param  array{metric: string, value: string, threshold: string, severity: string, wbs_node_id: string|null, wbs_label: string|null}  $breach
     */
    public function notify(Project $project, array $breach): int;
}
