<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Services\Sales\DocumentNumberingService;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Enums\MembershipStatus;
use Modules\Associations\Enums\MemberStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\MembershipType;

/**
 * The life of a membership: request, activation with card number and QR, renewal, suspension and end.
 */
final class MembershipService
{
    /** The card numbers of a company are one sequence: the numbering service keeps it under a fixed year. */
    private const int CardSequenceYear = 0;

    private const string CardSequence = 'association_card';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberingService $numbering,
        private readonly CredentialService $credentials,
        private readonly RegistryService $registry,
        private readonly FeeService $fees,
    ) {}

    public function request(Member $member, AssociativeYear $year, MembershipType $type): Membership
    {
        return Membership::create([
            'member_id' => $member->id,
            'year_id' => $year->id,
            'membership_type_id' => $type->id,
            'status' => MembershipStatus::Pending,
            'valid_from' => $year->starts_on,
            'valid_to' => $year->ends_on,
        ]);
    }

    /**
     * Admits a person: card number, QR valid until the end of the membership, registry type and fee of the type.
     */
    public function activate(Membership $membership): Membership
    {
        return DB::transaction(function () use ($membership): Membership {
            /** @var Membership $locked */
            $locked = Membership::query()->with(['member.contact', 'membershipType.feePlan', 'membershipType.registryType', 'year'])
                ->whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === MembershipStatus::Active) {
                return $locked;
            }

            if (! in_array($locked->status, [MembershipStatus::Pending, MembershipStatus::Suspended], true)) {
                throw AssociationException::conflict(__('associations.membership_cannot_activate'));
            }

            $isFirstActivation = $locked->status === MembershipStatus::Pending;

            if ($locked->card_number === null) {
                $this->assignCardNumber($locked);
            }

            $locked->status = MembershipStatus::Active;
            $locked->issued_on ??= CarbonImmutable::today();
            $locked->status_reason = null;
            $locked->save();

            $member = $locked->member;

            if ($member->status !== MemberStatus::Active) {
                $member->update(['status' => MemberStatus::Active]);
            }

            $this->credentials->issueQr($member, CarbonImmutable::parse($locked->valid_to)->endOfDay());

            if ($isFirstActivation) {
                $this->assignRegistryType($locked);
                $this->assignFee($locked);
            }

            return $locked;
        });
    }

    /**
     * Opens the membership of a following year with the same card number. It starts as a request unless told otherwise.
     */
    public function renew(Membership $membership, AssociativeYear $year, ?MembershipType $type = null, bool $activate = true): Membership
    {
        return DB::transaction(function () use ($membership, $year, $type, $activate): Membership {
            if (CarbonImmutable::parse($year->starts_on)->lessThanOrEqualTo(CarbonImmutable::parse($membership->year->starts_on))) {
                throw new AssociationException(__('associations.renewal_needs_later_year'));
            }

            $exists = Membership::query()->where('member_id', $membership->member_id)->where('year_id', $year->id)
                ->whereNotIn('status', [MembershipStatus::Rejected, MembershipStatus::Ceased])->exists();

            if ($exists) {
                throw AssociationException::conflict(__('associations.membership_already_exists'));
            }

            $renewed = Membership::create([
                'member_id' => $membership->member_id,
                'year_id' => $year->id,
                'membership_type_id' => ($type ?? $membership->membershipType)->id,
                'status' => MembershipStatus::Pending,
                'card_number' => $membership->card_number,
                'card_sequence' => $membership->card_sequence,
                'valid_from' => $year->starts_on,
                'valid_to' => $year->ends_on,
                'renewed_from_id' => $membership->id,
            ]);

            return $activate ? $this->activate($renewed) : $renewed;
        });
    }

    public function suspend(Membership $membership, ?string $reason): Membership
    {
        return $this->end($membership, MembershipStatus::Suspended, $reason);
    }

    /**
     * Ends a membership. The credentials stop working at once; with no other live membership the person is inactive.
     */
    public function cease(Membership $membership, ?string $reason): Membership
    {
        return $this->end($membership, MembershipStatus::Ceased, $reason);
    }

    public function reject(Membership $membership, ?string $reason): Membership
    {
        return $this->end($membership, MembershipStatus::Rejected, $reason);
    }

    /**
     * Marks as expired the memberships past their end.
     *
     * @return int how many
     */
    public function expirePast(): int
    {
        $expired = Membership::query()->where('status', MembershipStatus::Active)
            ->whereDate('valid_to', '<', CarbonImmutable::today()->toDateString())->get();

        $expired->each(static fn (Membership $membership) => $membership->update(['status' => MembershipStatus::Expired]));

        return $expired->count();
    }

    private function end(Membership $membership, MembershipStatus $status, ?string $reason): Membership
    {
        return DB::transaction(function () use ($membership, $status, $reason): Membership {
            $membership->update([
                'status' => $status,
                'status_reason' => $reason === null ? null : mb_substr($reason, 0, 500),
                'ceased_on' => $status === MembershipStatus::Ceased ? CarbonImmutable::today() : null,
            ]);

            $member = $membership->member;
            $live = $member->memberships()->whereKeyNot($membership->id)->where('status', MembershipStatus::Active)->exists();

            if (! $live) {
                $this->credentials->revokeAll($member, $status->value);

                if ($status === MembershipStatus::Ceased) {
                    $member->update(['status' => MemberStatus::Inactive]);
                }
            }

            return $membership;
        });
    }

    private function assignCardNumber(Membership $membership): void
    {
        $settings = AssociationSetting::current();
        $sequence = $this->numbering->next(self::CardSequence, self::CardSequenceYear, (string) $this->tenantContext->getTenantId())['sequential_number'];

        $membership->card_sequence = $sequence;
        $membership->card_number = $settings->card_prefix.str_pad((string) $sequence, $settings->card_padding, '0', STR_PAD_LEFT);
    }

    private function assignRegistryType(Membership $membership): void
    {
        $type = $membership->membershipType->registryType;

        if ($type !== null) {
            $this->registry->assignType($membership->member, $type, [
                'year_id' => $membership->year_id,
                'valid_from' => $membership->valid_from,
                'valid_to' => $membership->valid_to,
            ]);
        }
    }

    private function assignFee(Membership $membership): void
    {
        $plan = $membership->membershipType->feePlan;

        if ($plan !== null) {
            $this->fees->assign($membership->member, $plan, [
                'membership_id' => $membership->id,
                'starts_on' => CarbonImmutable::parse($membership->valid_from)->max(CarbonImmutable::today())->toDateString(),
                'ends_on' => CarbonImmutable::parse($membership->valid_to)->toDateString(),
            ]);
        }
    }
}
