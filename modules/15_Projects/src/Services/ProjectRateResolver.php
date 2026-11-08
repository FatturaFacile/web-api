<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectRateCard;
use Modules\Projects\Models\ProjectResource;

/**
 * Finds the hourly cost and billing rate of a person for a day of work on a project, from the most specific source:
 *
 *  1. rate card of the project for the person
 *  2. rate card of the project for the role
 *  3. company rate card for the person
 *  4. company rate card for the role
 *  5. default rates of the person's project resource
 *
 * Only cards valid on the work date are considered. When nothing matches the answer is null and the timesheet is
 * refused, rather than costing hours at an invented rate.
 */
final class ProjectRateResolver
{
    /**
     * @return array{cost_rate: string, bill_rate: string, source: string}|null
     */
    public function resolve(Project $project, string $userId, ?string $role, string $workDate): ?array
    {
        $candidates = [
            'project_user' => fn (Builder $query) => $query->where('project_id', $project->id)->where('user_id', $userId),
            'project_role' => $role !== null ? fn (Builder $query) => $query->where('project_id', $project->id)->whereNull('user_id')->where('role', $role) : null,
            'company_user' => fn (Builder $query) => $query->whereNull('project_id')->where('user_id', $userId),
            'company_role' => $role !== null ? fn (Builder $query) => $query->whereNull('project_id')->whereNull('user_id')->where('role', $role) : null,
        ];

        foreach ($candidates as $source => $scope) {
            if ($scope === null) {
                continue;
            }

            /** @var ProjectRateCard|null $card */
            $card = ProjectRateCard::query()
                ->where(fn (Builder $query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $workDate))
                ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $workDate))
                ->tap($scope)
                ->orderByDesc('valid_from')
                ->first();

            if ($card !== null) {
                return ['cost_rate' => (string) $card->cost_rate, 'bill_rate' => (string) $card->bill_rate, 'source' => $source];
            }
        }

        $resource = ProjectResource::query()->where('user_id', $userId)->where('is_active', true)->first();

        if ($resource !== null) {
            return ['cost_rate' => (string) $resource->internal_hourly_cost, 'bill_rate' => (string) $resource->billing_hourly_rate, 'source' => 'resource'];
        }

        return null;
    }
}
