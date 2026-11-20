<?php

declare(strict_types=1);

namespace Modules\Academy\Queries;

use Modules\Academy\Domain\Enums\AcademyLocale;
use Modules\Academy\Models\AcademyCertificate;
use Modules\Academy\Models\AcademyLearningTrack;
use Modules\Academy\Models\UserAcademyProgress;

/**
 * The tracks of the catalogue with the progress of one user of the tenant: the state of each mission, XP, badges and the
 * certificate of the finished tracks.
 */
final class GetAcademyTrackProgressQuery
{
    /**
     * @return array{total_xp: int, badges: list<string>, tracks: list<array<string, mixed>>}
     */
    public function execute(string $tenantId, string $userId, AcademyLocale $locale): array
    {
        $progress = UserAcademyProgress::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->get()
            ->keyBy('mission_id');

        $certificates = AcademyCertificate::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->get()
            ->keyBy('track_id');

        $tracks = [];

        foreach (AcademyLearningTrack::query()->with('missions')->orderBy('sort_order')->get() as $track) {
            $missions = [];
            $completed = 0;
            $xp = 0;

            foreach ($track->missions as $mission) {
                $row = $progress->get($mission->id);
                $status = $row instanceof UserAcademyProgress ? $row->status : 'not_started';
                $completed += $status === UserAcademyProgress::Completed ? 1 : 0;
                $xp += $row instanceof UserAcademyProgress ? $row->xp_awarded : 0;

                $missions[] = [
                    'id' => (string) $mission->id,
                    'code' => $mission->code,
                    'sequence' => $mission->sequence,
                    'difficulty' => $mission->difficulty->value,
                    'title' => $mission->text('title', $locale),
                    'description' => $mission->text('description', $locale),
                    'xp_reward' => $mission->xp_reward,
                    'badge_code' => $mission->badge_code,
                    'requires_sandbox' => $mission->requiresSandbox(),
                    'status' => $status,
                    'tour_steps' => array_map(static fn ($step): array => ['route' => $step->route, 'selector' => $step->selector, 'placement' => $step->placement, 'text' => $step->texts[$locale->value] ?? ($step->texts['en'] ?? '')], $mission->tourSteps()),
                ];
            }

            $certificate = $certificates->get($track->id);
            $total = count($missions);

            $tracks[] = [
                'id' => (string) $track->id,
                'code' => $track->code,
                'difficulty' => $track->difficulty->value,
                'title' => $track->text('title', $locale),
                'description' => $track->text('description', $locale),
                'missions_total' => $total,
                'missions_completed' => $completed,
                'completion_percent' => $total > 0 ? (int) floor($completed / $total * 100) : 0,
                'xp_earned' => $xp,
                'certificate_code' => $certificate?->certificate_code,
                'missions' => $missions,
            ];
        }

        return [
            'total_xp' => (int) $progress->sum('xp_awarded'),
            'badges' => $progress->pluck('badge_code')->filter()->unique()->values()->all(),
            'tracks' => $tracks,
        ];
    }
}
