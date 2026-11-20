<?php

declare(strict_types=1);

namespace Modules\Academy\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academy\Domain\Events\AcademyCertificateIssued;
use Modules\Academy\Domain\Events\AcademyDomainEvent;
use Modules\Academy\Domain\Events\AcademyMissionCompleted;
use Modules\Academy\Domain\Events\AcademyTrackCompleted;
use Modules\Academy\Models\AcademyCertificate;
use Modules\Academy\Models\InteractiveMission;
use Modules\Academy\Models\UserAcademyProgress;
use Modules\Academy\Services\SandboxTenantGuard;
use Modules\Academy\Services\VerificationRuleEvaluator;

/**
 * Reacts to an ERP domain event: every started mission of the tenant whose rule matches the event is completed, which
 * awards its XP and badge and, when it was the last mission of the track, issues the certificate of the user.
 *
 * Events of tenants that are not Demo / Sandbox tenants are ignored: production activity never advances the Academy.
 */
final readonly class EvaluateMissionCompletionEventCommand
{
    public function __construct(
        private SandboxTenantGuard $sandbox,
        private VerificationRuleEvaluator $evaluator,
        private TenantContext $tenantContext,
        private EventBusInterface $events,
    ) {}

    /**
     * @return int The number of missions completed by the event.
     */
    public function execute(DomainEventInterface $event): int
    {
        if ($event instanceof AcademyDomainEvent) {
            return 0;
        }

        $tenant = Tenant::query()->find($event->getTenantId());

        if ($tenant === null || ! $this->sandbox->isSandbox($tenant)) {
            return 0;
        }

        /** @var int $completed */
        $completed = $this->tenantContext->runFor($tenant, fn (): int => $this->complete($event, $tenant));

        return $completed;
    }

    private function complete(DomainEventInterface $event, Tenant $tenant): int
    {
        $completed = 0;
        $pending = UserAcademyProgress::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', UserAcademyProgress::Started)
            ->with('mission')
            ->get();

        foreach ($pending as $progress) {
            $mission = $progress->mission;

            if (! $mission instanceof InteractiveMission || ! $this->evaluator->matches($mission->rule(), $event->getEventName(), $event->toPayload())) {
                continue;
            }

            $completed++;
            $this->award($tenant, $progress, $mission);
        }

        return $completed;
    }

    private function award(Tenant $tenant, UserAcademyProgress $progress, InteractiveMission $mission): void
    {
        $certificate = DB::transaction(function () use ($tenant, $progress, $mission): ?AcademyCertificate {
            $progress->forceFill([
                'status' => UserAcademyProgress::Completed,
                'xp_awarded' => $mission->xp_reward,
                'badge_code' => $mission->badge_code,
                'completed_at' => now(),
            ])->save();

            return $this->trackIsComplete($tenant, $progress) ? $this->issueCertificate($tenant, $progress) : null;
        });

        $userId = $progress->user_id;
        $this->events->dispatch(new AcademyMissionCompleted((string) $tenant->id, $userId, (string) $mission->id, [
            'mission_code' => $mission->code,
            'xp_awarded' => $mission->xp_reward,
            'badge_code' => $mission->badge_code,
        ]));

        if ($certificate !== null) {
            $this->events->dispatch(new AcademyTrackCompleted((string) $tenant->id, $userId, $progress->track_id, ['certificate_code' => $certificate->certificate_code]));
            $this->events->dispatch(new AcademyCertificateIssued((string) $tenant->id, $userId, (string) $certificate->id, ['certificate_code' => $certificate->certificate_code, 'track_id' => $progress->track_id]));
        }
    }

    private function trackIsComplete(Tenant $tenant, UserAcademyProgress $progress): bool
    {
        $total = InteractiveMission::query()->where('track_id', $progress->track_id)->count();
        $done = UserAcademyProgress::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $progress->user_id)
            ->where('track_id', $progress->track_id)
            ->where('status', UserAcademyProgress::Completed)
            ->count();

        return $total > 0 && $done >= $total;
    }

    private function issueCertificate(Tenant $tenant, UserAcademyProgress $progress): AcademyCertificate
    {
        $existing = AcademyCertificate::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $progress->user_id)
            ->where('track_id', $progress->track_id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $totalXp = (int) UserAcademyProgress::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $progress->user_id)
            ->where('track_id', $progress->track_id)
            ->sum('xp_awarded');

        return AcademyCertificate::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $progress->user_id,
            'track_id' => $progress->track_id,
            'certificate_code' => 'ACA-'.strtoupper(Str::random(16)),
            'holder_name' => (string) (User::query()->whereKey($progress->user_id)->value('name') ?? ''),
            'total_xp' => $totalXp,
            'issued_at' => now(),
        ]);
    }
}
