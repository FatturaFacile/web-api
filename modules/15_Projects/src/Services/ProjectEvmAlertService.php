<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Modules\Projects\Contracts\ProjectEvmAlertGatewayInterface;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectEvmAlertState;
use Modules\Projects\Support\EvmCalculator;

/**
 * Opens and resolves the earned value threshold alerts of a project and its WBS nodes (CPI below 0.90, SPI below 0.90,
 * budget absorption above 80%) and hands each newly opened breach to the Notification & Alert Engine (Mod 008).
 *
 * The state of every (node, metric) pair is stored, so a breach is notified once when it opens, again only if it worsens
 * from warning to critical, and once more when it was resolved and breaches anew: repeated snapshots of the same breach
 * never produce duplicate alerts.
 */
final readonly class ProjectEvmAlertService
{
    public function __construct(private ProjectEvmAlertGatewayInterface $gateway) {}

    /**
     * @param  list<array{wbs_node_id: string|null, wbs_label: string|null, cpi: string|null, spi: string|null, absorption: string|null}>  $nodes
     * @return int the number of alerts notified
     */
    public function evaluate(Project $project, array $nodes): int
    {
        $notified = 0;

        foreach ($nodes as $node) {
            $nodeKey = $node['wbs_node_id'] ?? 'project';
            $breaches = [];

            foreach (EvmCalculator::breaches($node['cpi'], $node['spi'], $node['absorption']) as $breach) {
                $breaches[$breach['metric']] = $breach;
            }

            foreach (['cpi', 'spi', 'absorption'] as $metric) {
                $state = ProjectEvmAlertState::query()->where('project_id', $project->id)->where('node_key', $nodeKey)->where('metric', $metric)->first();
                $breach = $breaches[$metric] ?? null;

                if ($breach === null) {
                    if ($state !== null && $state->is_open) {
                        $state->update(['is_open' => false, 'resolved_at' => now()]);
                    }

                    continue;
                }

                $isNew = $state === null || ! $state->is_open;
                $escalated = $state !== null && $state->is_open && $state->severity === 'warning' && $breach['severity'] === 'critical';

                $state ??= new ProjectEvmAlertState(['tenant_id' => $project->tenant_id, 'company_id' => $project->company_id, 'project_id' => $project->id, 'wbs_node_id' => $node['wbs_node_id'], 'metric' => $metric, 'node_key' => $nodeKey]);
                $state->fill([
                    'severity' => $breach['severity'],
                    'metric_value' => $breach['value'],
                    'threshold' => $breach['threshold'],
                    'is_open' => true,
                    'opened_at' => $isNew ? now() : $state->opened_at,
                    'resolved_at' => null,
                ]);

                if ($isNew || $escalated) {
                    $this->gateway->notify($project, [...$breach, 'wbs_node_id' => $node['wbs_node_id'], 'wbs_label' => $node['wbs_label']]);
                    $state->last_notified_at = now();
                    $notified++;
                }

                $state->save();
            }
        }

        return $notified;
    }
}
