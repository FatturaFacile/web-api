<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Enums\AccessReason;
use Modules\Associations\Enums\CertificateKind;
use Modules\Associations\Enums\CourseEnrolmentStatus;
use Modules\Associations\Enums\FeeStanding;
use Modules\Associations\Models\AccessArea;
use Modules\Associations\Models\AccessDevice;
use Modules\Associations\Models\AccessLog;
use Modules\Associations\Models\AreaPresence;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseEnrolment;
use Modules\Associations\Models\CourseInstructor;
use Modules\Associations\Models\Credential;
use Modules\Associations\Models\Member;

/**
 * Decides at the reader, in real time: credential, membership, fees, course of the moment, medical certificate and room
 * left in the area, in this order. Every attempt ends in the access log. The answer carries no personal data.
 */
final class AccessVerifier
{
    public function __construct(
        private readonly CredentialService $credentials,
        private readonly FeeStandingService $fees,
        private readonly CertificateService $certificates,
        private readonly CourseCalendar $calendar,
    ) {}

    /**
     * @return array{decision: string, reason_code: string, area: string, occupancy: int, capacity: ?int}
     */
    public function verify(AccessDevice $device, string $presented, ?string $direction = null): array
    {
        return DB::transaction(function () use ($device, $presented, $direction): array {
            /** @var AccessArea $area */
            $area = AccessArea::query()->whereKey($device->area_id)->lockForUpdate()->firstOrFail();
            $direction = in_array($direction, ['in', 'out'], true) ? $direction : ($device->direction === 'out' ? 'out' : 'in');
            $now = CarbonImmutable::now();

            $this->closeStalePresences($area, $now);

            ['credential' => $credential, 'reason' => $reason] = $this->credentials->resolve($presented, (string) $device->tenant_id);
            $member = $credential?->member;

            if ($reason === null && $member !== null) {
                $reason = $direction === 'out' ? $this->leave($area, $member, $now) : $this->enter($area, $member, $now);
            }

            $reason ??= AccessReason::UnknownCredential;

            $this->log($device, $area, $credential, $member, $direction, $reason, $now);
            $device->update(['last_seen_at' => $now]);

            return [
                'decision' => $reason->isAllowed() ? 'allow' : 'deny',
                'reason_code' => $reason->value,
                'area' => (string) $area->code,
                'occupancy' => $this->occupancy($area),
                'capacity' => $area->capacity,
            ];
        });
    }

    public function occupancy(AccessArea $area): int
    {
        return AreaPresence::query()->where('area_id', $area->id)->whereNull('left_at')->count();
    }

    private function enter(AccessArea $area, Member $member, CarbonImmutable $now): AccessReason
    {
        $problem = $this->problemFor($area, $member, $now);

        if ($problem !== null) {
            return $problem;
        }

        $isInside = AreaPresence::query()->where('area_id', $area->id)->where('member_id', $member->id)->whereNull('left_at')->exists();

        if ($isInside) {
            return AccessReason::Granted;
        }

        if ($area->capacity !== null && $this->occupancy($area) >= $area->capacity) {
            return AccessReason::AreaFull;
        }

        AreaPresence::create(['area_id' => $area->id, 'member_id' => $member->id, 'entered_at' => $now]);

        return AccessReason::Granted;
    }

    private function leave(AccessArea $area, Member $member, CarbonImmutable $now): AccessReason
    {
        AreaPresence::query()->where('area_id', $area->id)->where('member_id', $member->id)->whereNull('left_at')->update(['left_at' => $now]);

        return AccessReason::ExitRecorded;
    }

    private function problemFor(AccessArea $area, Member $member, CarbonImmutable $now): ?AccessReason
    {
        if (! $area->is_active) {
            return AccessReason::AreaDisabled;
        }

        if ($member->currentMembership($now->startOfDay()) === null) {
            return $member->memberships()->exists() ? AccessReason::MembershipInactive : AccessReason::MembershipMissing;
        }

        if ($area->check_fee && $this->fees->of($member) === FeeStanding::Overdue) {
            return AccessReason::FeeOverdue;
        }

        $course = null;

        if ($area->check_course) {
            ['course' => $course, 'reason' => $reason] = $this->courseNow($area, $member, $now);

            if ($reason !== null) {
                return $reason;
            }
        }

        if ($area->check_certificate) {
            $required = $course?->required_certificate ?? $area->required_certificate ?? CertificateKind::NonCompetitive;

            return $this->certificates->problem($member, $course?->discipline_id, $required, $now->startOfDay());
        }

        return null;
    }

    /**
     * The course of this area the person has right now, as participant or instructor.
     *
     * @return array{course: ?Course, reason: ?AccessReason}
     */
    private function courseNow(AccessArea $area, Member $member, CarbonImmutable $now): array
    {
        $courseIds = CourseEnrolment::query()->where('member_id', $member->id)->where('status', CourseEnrolmentStatus::Enrolled)->pluck('course_id')
            ->merge(CourseInstructor::query()->where('member_id', $member->id)->pluck('course_id'));

        $courses = Course::query()->whereIn('id', $courseIds)->where('access_area_id', $area->id)->where('status', Course::Active)->get();
        $margin = $area->course_margin_minutes;
        $wasSuspended = false;

        foreach ($courses as $course) {
            foreach ($this->calendar->occurrences($course, $now->startOfDay(), $now->startOfDay()) as $lesson) {
                $opens = CarbonImmutable::parse("{$lesson['date']} {$lesson['starts_at']}")->subMinutes($margin);
                $closes = CarbonImmutable::parse("{$lesson['date']} {$lesson['ends_at']}")->addMinutes($margin);

                if ($now->between($opens, $closes)) {
                    if (! $lesson['suspended']) {
                        return ['course' => $course, 'reason' => null];
                    }

                    $wasSuspended = true;
                }
            }
        }

        return ['course' => null, 'reason' => $wasSuspended ? AccessReason::CourseSuspended : AccessReason::NoCourseNow];
    }

    /**
     * Whoever left without passing a reader does not keep a place for ever.
     */
    private function closeStalePresences(AccessArea $area, CarbonImmutable $now): void
    {
        $hours = AssociationSetting::current()->presence_max_hours;

        AreaPresence::query()->where('area_id', $area->id)->whereNull('left_at')
            ->where('entered_at', '<', $now->subHours($hours))
            ->update(['left_at' => $now]);
    }

    private function log(AccessDevice $device, AccessArea $area, ?Credential $credential, ?Member $member, string $direction, AccessReason $reason, CarbonImmutable $now): void
    {
        AccessLog::create([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'area_id' => $area->id,
            'member_id' => $member?->id,
            'credential_id' => $credential?->id,
            'direction' => $direction,
            'decision' => $reason->isAllowed() ? 'allow' : 'deny',
            'reason_code' => $reason,
            'occurred_at' => $now,
        ]);
    }
}
