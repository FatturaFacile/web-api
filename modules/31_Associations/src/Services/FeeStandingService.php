<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Modules\Associations\Enums\FeeStanding;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\Member;

/**
 * The state of the fees of a person, overall or for one course.
 */
final class FeeStandingService
{
    public function of(Member $member, ?string $courseId = null): FeeStanding
    {
        return $this->ofMembers([$member->id], $courseId)[$member->id];
    }

    /**
     * @param  list<string>  $memberIds
     * @return array<string, FeeStanding>
     */
    public function ofMembers(array $memberIds, ?string $courseId = null): array
    {
        $today = CarbonImmutable::today();
        $assignments = FeeAssignment::query()
            ->with(['instalments' => static fn ($query) => $query->where('status', '!=', InstalmentStatus::Cancelled)])
            ->whereIn('member_id', $memberIds)
            ->where('status', FeeAssignment::Active)
            ->when($courseId !== null, static fn ($query) => $query->where('course_id', $courseId))
            ->get()
            ->groupBy('member_id');

        $standings = [];

        foreach ($memberIds as $memberId) {
            $standings[$memberId] = $this->standing($assignments->get($memberId)?->flatMap->instalments, $assignments->has($memberId), $today);
        }

        return $standings;
    }

    private function standing(mixed $instalments, bool $hasFees, CarbonImmutable $today): FeeStanding
    {
        if (! $hasFees) {
            return FeeStanding::Attention;
        }

        $unsettled = $instalments->filter(static fn ($instalment): bool => in_array($instalment->status, [InstalmentStatus::Open, InstalmentStatus::Partial], true));

        if ($unsettled->contains(static fn ($instalment): bool => $instalment->due_on->lessThan($today))) {
            return FeeStanding::Overdue;
        }

        return $unsettled->contains(static fn ($instalment): bool => $instalment->status === InstalmentStatus::Partial) ? FeeStanding::Attention : FeeStanding::Settled;
    }
}
