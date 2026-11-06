<?php

declare(strict_types=1);

namespace Modules\Associations\DTOs;

use App\Models\Contact;
use App\Models\PortalAccessToken;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\Member;

/**
 * Who is using the member area: a contact, what it is in the registry, the minors in its care and the courses it
 * teaches. Every read of the member area goes through this, so a token reaches nothing else.
 */
final readonly class AreaAccess
{
    /**
     * @param  Collection<int, Member>  $wards
     * @param  Collection<int, Course>  $courses
     */
    public function __construct(
        public PortalAccessToken $token,
        public Contact $contact,
        public ?Member $self,
        public Collection $wards,
        public Collection $courses,
    ) {}

    /**
     * @return Collection<int, Member>
     */
    public function subjects(): Collection
    {
        return $this->self === null ? $this->wards : $this->wards->prepend($this->self);
    }

    /**
     * A person this access can see: itself or a minor in its care.
     *
     * @throws ModelNotFoundException for everybody else
     */
    public function subject(string $memberId): Member
    {
        return $this->subjects()->firstOrFail(static fn (Member $member): bool => $member->id === $memberId);
    }

    public function isGuardianOf(Member $member): bool
    {
        return $this->wards->contains(static fn (Member $ward): bool => $ward->id === $member->id);
    }

    public function course(string $courseId): Course
    {
        return $this->courses->firstOrFail(static fn (Course $course): bool => $course->id === $courseId);
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return array_values(array_filter([
            $this->self !== null ? 'member' : null,
            $this->wards->isNotEmpty() ? 'guardian' : null,
            $this->courses->isNotEmpty() ? 'instructor' : null,
        ]));
    }
}
