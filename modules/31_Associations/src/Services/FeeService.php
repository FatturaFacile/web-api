<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Enums\FeeFrequency;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\FamilyDiscount;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\FeeInstalment;
use Modules\Associations\Models\FeePlan;
use Modules\Associations\Models\Member;
use Modules\Associations\Support\Money;

/**
 * Turns a fee plan into what one person owes: the family discount, the calendar of instalments, the lessons of a
 * package and the charges per attendance.
 */
final class FeeService
{
    public function __construct(private readonly GuardianService $guardians) {}

    /**
     * @param  array{starts_on?: ?string, ends_on?: ?string, course_id?: ?string, membership_id?: ?string, payer_contact_id?: ?string, apply_family_discount?: bool}  $options
     */
    public function assign(Member $member, FeePlan $plan, array $options = []): FeeAssignment
    {
        return DB::transaction(function () use ($member, $plan, $options): FeeAssignment {
            $startsOn = CarbonImmutable::parse($options['starts_on'] ?? CarbonImmutable::today());
            $endsOn = isset($options['ends_on']) ? CarbonImmutable::parse($options['ends_on']) : $startsOn->addYear()->subDay();

            if ($endsOn->lessThan($startsOn)) {
                throw new AssociationException(__('associations.fee_period_invalid'));
            }

            $discount = ($options['apply_family_discount'] ?? true) ? $this->familyDiscount($member, $plan) : null;
            $base = Money::normalize((string) $plan->amount);
            $reduction = $discount === null ? '0.00' : Money::percentage($base, (string) $discount->percentage);
            $unit = Money::sub($base, $reduction);
            $hasPackage = $plan->lessons_included !== null;

            $schedule = $plan->frequency === FeeFrequency::PerAttendance && ! $hasPackage
                ? []
                : $this->schedule($plan, $startsOn, $endsOn, $unit);

            $periods = max(1, $this->periodCount($plan, $startsOn, $endsOn));
            $charged = $schedule === [];

            $assignment = FeeAssignment::create([
                'member_id' => $member->id,
                'fee_plan_id' => $plan->id,
                'payer_contact_id' => $options['payer_contact_id'] ?? $this->guardians->payerContactId($member),
                'course_id' => $options['course_id'] ?? null,
                'membership_id' => $options['membership_id'] ?? null,
                'family_discount_id' => $discount?->id,
                'description' => $plan->name,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'gross_amount' => $charged ? '0.00' : bcmul($base, (string) $periods, 2),
                'discount_amount' => $charged ? '0.00' : bcmul($reduction, (string) $periods, 2),
                'net_amount' => array_reduce(array_column($schedule, 'amount'), Money::add(...), '0.00'),
                'per_attendance_amount' => $charged ? $unit : null,
                'lessons_total' => $plan->lessons_included,
                'lessons_remaining' => $plan->lessons_included,
                'status' => FeeAssignment::Active,
            ]);

            foreach ($schedule as $index => $instalment) {
                $assignment->instalments()->create([...$instalment, 'sequence' => $index + 1, 'status' => InstalmentStatus::Open]);
            }

            return $assignment->load('instalments');
        });
    }

    /**
     * A single charge without a plan, such as a ticket.
     */
    public function charge(Member $member, string $description, string $amount, CarbonImmutable $dueOn, ?string $courseId = null): FeeAssignment
    {
        return DB::transaction(function () use ($member, $description, $amount, $dueOn, $courseId): FeeAssignment {
            $assignment = FeeAssignment::create([
                'member_id' => $member->id,
                'payer_contact_id' => $this->guardians->payerContactId($member),
                'course_id' => $courseId,
                'description' => $description,
                'starts_on' => $dueOn,
                'gross_amount' => $amount,
                'net_amount' => $amount,
                'status' => FeeAssignment::Active,
            ]);

            $assignment->instalments()->create(['sequence' => 1, 'due_on' => $dueOn, 'amount' => $amount, 'status' => InstalmentStatus::Open]);

            return $assignment->load('instalments');
        });
    }

    /**
     * Adds what one attendance costs on a fee charged per attendance.
     */
    public function chargeAttendance(FeeAssignment $assignment, CarbonImmutable $day): FeeInstalment
    {
        return DB::transaction(function () use ($assignment, $day): FeeInstalment {
            /** @var FeeAssignment $locked */
            $locked = FeeAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $amount = (string) $locked->per_attendance_amount;

            $locked->update([
                'gross_amount' => Money::add((string) $locked->gross_amount, $amount),
                'net_amount' => Money::add((string) $locked->net_amount, $amount),
            ]);

            /** @var FeeInstalment */
            return $locked->instalments()->create([
                'sequence' => (int) $locked->instalments()->withTrashed()->max('sequence') + 1,
                'due_on' => $day,
                'amount' => $amount,
                'status' => InstalmentStatus::Open,
            ]);
        });
    }

    public function cancel(FeeAssignment $assignment): FeeAssignment
    {
        return DB::transaction(function () use ($assignment): FeeAssignment {
            $assignment->update(['status' => FeeAssignment::Cancelled]);
            $assignment->instalments()->where('status', InstalmentStatus::Open)->get()
                ->each(static fn (FeeInstalment $instalment) => $instalment->update(['status' => InstalmentStatus::Cancelled]));

            return $assignment;
        });
    }

    /**
     * The best discount the position of this person in the family reaches on this plan.
     */
    private function familyDiscount(Member $member, FeePlan $plan): ?FamilyDiscount
    {
        if ($member->family_id === null) {
            return null;
        }

        $alreadyPaying = FeeAssignment::query()
            ->where('fee_plan_id', $plan->id)
            ->where('status', FeeAssignment::Active)
            ->where('member_id', '!=', $member->id)
            ->whereHas('member', static fn ($query) => $query->where('family_id', $member->family_id))
            ->distinct()
            ->count('member_id');

        /** @var ?FamilyDiscount */
        return FamilyDiscount::query()
            ->where('is_active', true)
            ->where('from_position', '<=', $alreadyPaying + 1)
            ->orderByDesc('from_position')
            ->first();
    }

    /**
     * @return list<array{due_on: CarbonImmutable, amount: string}>
     */
    private function schedule(FeePlan $plan, CarbonImmutable $startsOn, CarbonImmutable $endsOn, string $unit): array
    {
        $parts = $plan->instalments;

        if ($parts !== null && $parts > 1) {
            $total = bcmul($unit, (string) max(1, $this->periodCount($plan, $startsOn, $endsOn)), 2);

            return array_map(
                static fn (string $amount, int $index): array => ['due_on' => $startsOn->addMonthsNoOverflow($index), 'amount' => $amount],
                Money::split($total, $parts),
                range(0, $parts - 1),
            );
        }

        $months = $plan->frequency->months();

        if ($months === null) {
            return [['due_on' => $startsOn, 'amount' => $unit]];
        }

        $schedule = [];

        for ($index = 0; ($dueOn = $startsOn->addMonthsNoOverflow($index * $months))->lessThanOrEqualTo($endsOn); $index++) {
            $schedule[] = ['due_on' => $dueOn, 'amount' => $unit];
        }

        return $schedule;
    }

    private function periodCount(FeePlan $plan, CarbonImmutable $startsOn, CarbonImmutable $endsOn): int
    {
        $months = $plan->frequency->months();

        if ($months === null) {
            return 1;
        }

        $count = 0;

        while ($startsOn->addMonthsNoOverflow($count * $months)->lessThanOrEqualTo($endsOn)) {
            $count++;
        }

        return $count;
    }
}
