<?php

declare(strict_types=1);

namespace Modules\Childcare\Services;

use App\Exceptions\BusinessRuleException;
use App\Services\Tenancy\TenantClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Models\Group;

/**
 * Enrolment of children: a child is registered as active or on the waiting list, a group never holds more active
 * children than its capacity (0 means no limit), and a waiting child is admitted in the order of the applications.
 */
final class EnrolmentService
{
    public function __construct(private readonly TenantClock $clock) {}

    /**
     * Registers a child, active or waiting. An active child with a group needs a place in it.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, string $referenceCode): Child
    {
        return DB::transaction(function () use ($data, $referenceCode): Child {
            $status = (string) ($data['status'] ?? 'active');

            if ($status === 'active') {
                $this->assertPlace($data['group_id'] ?? null, null);
            }

            $child = new Child;
            $child->fill([...$data, 'reference_code' => $referenceCode, 'status' => $status]);
            $child->save();

            return $child;
        });
    }

    /**
     * Changes a child. A move to another group, or back to active, needs a place in the group.
     *
     * @param  array<string, mixed>  $data
     */
    public function change(Child $child, array $data): Child
    {
        return DB::transaction(function () use ($child, $data): Child {
            $locked = Child::query()->lockForUpdate()->findOrFail($child->id);
            $status = (string) ($data['status'] ?? $locked->status);
            $groupId = array_key_exists('group_id', $data) ? $data['group_id'] : $locked->group_id;
            $moves = $groupId !== $locked->group_id || ($status === 'active' && $locked->status !== 'active');

            if ($status === 'active' && $moves) {
                $this->assertPlace($groupId, $locked->id);
            }

            $locked->update($data);

            return $locked;
        });
    }

    /**
     * Admits a waiting child into a group (its own or the one given): the child becomes active on the date.
     */
    public function admit(Child $child, ?string $groupId, ?string $enrolledOn): Child
    {
        return DB::transaction(function () use ($child, $groupId, $enrolledOn): Child {
            $locked = Child::query()->lockForUpdate()->findOrFail($child->id);

            if ($locked->status !== 'waiting') {
                throw new BusinessRuleException('Only a child on the waiting list can be admitted.');
            }

            $target = $groupId ?? $locked->group_id;
            $this->assertPlace($target, $locked->id);
            $locked->update(['status' => 'active', 'group_id' => $target, 'enrolled_on' => $enrolledOn ?? $this->clock->today()->toDateString()]);

            return $locked;
        });
    }

    /**
     * Children waiting for a place, in the order of their applications, with their position.
     *
     * @return Collection<int, Child>
     */
    public function waitingList(): Collection
    {
        return Child::query()->where('status', 'waiting')->orderBy('enrolled_on')->orderBy('created_at')->get()
            ->each(static fn (Child $child, int $index) => $child->setAttribute('position', $index + 1));
    }

    /**
     * The groups with the active children they hold and the places left (null when the capacity is unlimited).
     *
     * @return Collection<int, Group>
     */
    public function occupancy(): Collection
    {
        $active = Child::query()->where('status', 'active')->whereNotNull('group_id')->get()->countBy('group_id');

        return Group::query()->orderBy('name')->get()->each(static function (Group $group) use ($active): void {
            $count = (int) ($active[$group->id] ?? 0);
            $group->setAttribute('active_children', $count);
            $group->setAttribute('places_left', $group->capacity > 0 ? max(0, $group->capacity - $count) : null);
        });
    }

    /**
     * Refuses a full group. The group row is locked so two admissions cannot take the last place together.
     */
    private function assertPlace(?string $groupId, ?string $ignoreChildId): void
    {
        if ($groupId === null) {
            return;
        }

        $group = Group::query()->lockForUpdate()->findOrFail($groupId);

        if ($group->capacity <= 0) {
            return;
        }

        $active = Child::query()->where('group_id', $group->id)->where('status', 'active')
            ->when($ignoreChildId !== null, fn ($query) => $query->where('id', '!=', $ignoreChildId))->count();

        if ($active >= $group->capacity) {
            throw new BusinessRuleException("The group {$group->name} is full.");
        }
    }
}
