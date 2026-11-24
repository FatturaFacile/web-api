<?php

declare(strict_types=1);

namespace App\Queries\Mobile;

use App\Models\EmployeeShift;
use App\Models\MobileDeviceSession;
use App\Models\PayslipDocument;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSite;
use App\Services\Mobile\RoleResolver;
use Illuminate\Database\Eloquent\Builder;
use Modules\Workflow\Models\WorkflowInstanceStep;

/** Query: GetMobileBootstrapPayload */
final class GetMobileBootstrapPayloadQuery
{
    /**
     * @return array<string, mixed>
     */
    public function handle(User $user, Tenant $tenant, ?MobileDeviceSession $device = null): array
    {
        $workspaces = $this->workspacesFor($user, $tenant);

        $modules = collect($workspaces)
            ->flatMap(fn (string $w) => config("mobile.workspace_modules.{$w}", []))
            ->unique()
            ->values()
            ->all();

        $minVersion = (string) config('mobile.min_app_version');

        return [
            'server_time' => now()->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'roles' => app(RoleResolver::class)->roles($user, $tenant->id),
            ],
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'country' => $tenant->country,
                'default_currency' => $tenant->default_currency,
            ],
            'locales' => config('mobile.supported_locales'),
            'workspaces' => $workspaces,
            'modules' => $modules,
            'app' => [
                'min_version' => $minVersion,
                'update_required' => $device?->app_version !== null
                    && version_compare((string) $device->app_version, $minVersion, '<'),
            ],
            'device' => $device === null ? null : [
                'id' => $device->id,
                'device_id' => $device->device_id,
                'platform' => $device->platform,
                'biometric_enrolled' => $device->biometric_enrolled,
                'push_registered' => $device->push_token !== null,
            ],
            'badges' => [
                'pending_approvals' => $this->pendingApprovals($user)->count(),
                'unread_documents' => PayslipDocument::where('user_id', $user->id)
                    ->whereNotNull('published_at')->whereNull('read_at')->count(),
            ],
            'attendance' => [
                'open_entry' => TimeEntry::where('user_id', $user->id)->whereNull('clock_out')->latest('clock_in')->first(['id', 'clock_in']),
                'work_sites' => WorkSite::where('is_active', true)->get(['id', 'name', 'latitude', 'longitude', 'radius_meters']),
                'upcoming_shifts' => EmployeeShift::where('user_id', $user->id)
                    ->where('ends_at', '>=', now())->where('starts_at', '<=', now()->addDays(7))
                    ->orderBy('starts_at')->limit(14)->get(['id', 'work_site_id', 'starts_at', 'ends_at', 'shift_type']),
            ],
            'sync' => [
                'entities' => array_keys(config('mobile.sync_entities', [])),
                'page_size' => (int) config('mobile.sync_page_size'),
                'manifest' => $device?->manifests()->get(['entity_name', 'last_synced_at', 'record_count', 'storage_bytes']) ?? [],
            ],
        ];
    }

    /** @return list<string> */
    public function workspacesFor(User $user, Tenant $tenant): array
    {
        $workspaces = ['employee'];
        $all = array_keys(config('mobile.workspace_roles', []));

        $resolver = app(RoleResolver::class);
        if ($resolver->isAdmin($user, $tenant->id)) {
            return array_values(array_unique(array_merge($workspaces, $all)));
        }

        foreach (config('mobile.workspace_roles', []) as $workspace => $roles) {
            if ($resolver->hasAny($user, $roles, $tenant->id)) {
                $workspaces[] = $workspace;
            }
        }

        // anyone with approvals waiting gets the manager inbox
        if (! in_array('manager', $workspaces, true) && $this->pendingApprovals($user)->exists()) {
            $workspaces[] = 'manager';
        }

        return array_values(array_unique($workspaces));
    }

    /** @return Builder<WorkflowInstanceStep> */
    private function pendingApprovals(User $user)
    {
        return WorkflowInstanceStep::where('status', 'pending')
            ->where(function ($q) use ($user): void {
                $q->where('assigned_user_id', $user->id)
                    ->orWhereIn('assigned_role', app(RoleResolver::class)->roles($user));
            });
    }
}
