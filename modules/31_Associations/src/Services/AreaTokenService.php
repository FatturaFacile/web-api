<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Models\Contact;
use App\Models\PortalAccessToken;
use App\Services\Portal\PortalTokenService;
use Carbon\CarbonImmutable;
use Modules\Associations\DTOs\AreaAccess;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseInstructor;
use Modules\Associations\Models\Guardianship;
use Modules\Associations\Models\Member;

/**
 * Access to the member area, on the magic links of the customer portal: a separate token type, so that a link of the
 * area never opens the portal and the other way round. Each person, and each guardian, has a personal link; minors act
 * through their guardians.
 */
final class AreaTokenService
{
    public const string TokenType = 'association_area';

    public function __construct(
        private readonly PortalTokenService $tokens,
        private readonly AssociationMailer $mailer,
    ) {}

    /**
     * Issues a personal link and sends it to the contact. The token itself is only ever in the mail.
     */
    public function issue(Contact $contact): void
    {
        $member = Member::query()->where('contact_id', $contact->id)->first();
        $isGuardian = Guardianship::query()->active()->where('guardian_contact_id', $contact->id)->exists();

        if ($member === null && ! $isGuardian) {
            throw new AssociationException(__('associations.area_not_a_person'));
        }

        if ($member !== null && $member->isMinor() && ! $isGuardian) {
            throw new AssociationException(__('associations.area_minor'));
        }

        if ($contact->email === null || $contact->email === '') {
            throw new AssociationException(__('associations.area_no_email'));
        }

        $this->revokeAll($contact);

        $issued = $this->tokens->createToken($contact, self::TokenType, expiresAt: CarbonImmutable::now()->addDays((int) config('associations.area_token_days')));

        $this->mailer->send(
            $contact->email,
            __('associations.mail.area_subject'),
            [__('associations.mail.area_line')],
            rtrim((string) config('associations.area_url'), '/').'?token='.$issued['token'],
        );
    }

    public function revokeAll(Contact $contact): int
    {
        return PortalAccessToken::query()
            ->where('contact_id', $contact->id)
            ->where('token_type', self::TokenType)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Reads a token into the access of its holder and binds the request to its tenant.
     */
    public function access(string $token): AreaAccess
    {
        $accessToken = $this->tokens->resolveToken($token, self::TokenType);
        $contact = Contact::query()->findOrFail($accessToken->contact_id);
        $self = Member::query()->with('contact')->where('contact_id', $contact->id)->first();

        $wards = Member::query()->with('contact')->whereIn(
            'id',
            Guardianship::query()->active()->where('guardian_contact_id', $contact->id)->pluck('minor_member_id'),
        )->get()->filter(static fn (Member $ward): bool => $ward->isMinor())->values();

        $today = CarbonImmutable::today()->toDateString();

        $courses = $self === null ? collect() : Course::query()->whereIn(
            'id',
            CourseInstructor::query()->where('member_id', $self->id)
                ->where(static fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today))
                ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today))
                ->pluck('course_id'),
        )->get();

        return new AreaAccess($accessToken, $contact, $self !== null && $self->isMinor() ? null : $self, $wards, $courses);
    }
}
