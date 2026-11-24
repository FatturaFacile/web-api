<?php

declare(strict_types=1);

namespace Modules\Academy\Commands;

use App\Models\Tenant;
use App\Models\User;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Academy\Domain\Events\AcademyMissionStarted;
use Modules\Academy\Models\InteractiveMission;
use Modules\Academy\Models\UserAcademyProgress;
use Modules\Academy\Services\SandboxTenantGuard;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Starts a mission for a user. A mission that works on ERP data can be started only in a Demo / Sandbox tenant.
 */
final readonly class StartAcademyMissionCommand
{
    public function __construct(private SandboxTenantGuard $sandbox, private EventBusInterface $events) {}

    /**
     * @throws ModelNotFoundException
     * @throws UnprocessableEntityHttpException
     */
    public function execute(User $user, string $tenantId, string $missionId): UserAcademyProgress
    {
        $mission = InteractiveMission::query()->findOrFail($missionId);
        $tenant = Tenant::query()->findOrFail($tenantId);

        if ($mission->requiresSandbox() && ! $this->sandbox->isSandbox($tenant)) {
            throw new UnprocessableEntityHttpException('This mission can be started only in a Demo / Sandbox tenant.');
        }

        $progress = DB::transaction(function () use ($user, $tenantId, $mission): UserAcademyProgress {
            $progress = UserAcademyProgress::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'user_id' => (string) $user->id, 'mission_id' => $mission->id],
                ['track_id' => $mission->track_id, 'status' => UserAcademyProgress::Started, 'started_at' => now()],
            );

            return $progress;
        });

        if ($progress->wasRecentlyCreated) {
            $this->events->dispatch(new AcademyMissionStarted($tenantId, (string) $user->id, (string) $mission->id, ['mission_code' => $mission->code]));
        }

        return $progress;
    }
}
