<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Enums\MembershipStatus;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\FeeInstalment;
use Modules\Associations\Models\MedicalCertificate;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Membership;

/**
 * The scheduled reminders. The sent notices table makes every run repeatable: a reminder goes out once, whatever the
 * number of runs of the job.
 */
final class ReminderService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AssociationMailer $mailer,
        private readonly CertificateService $certificates,
        private readonly GuardianService $guardians,
    ) {}

    /**
     * @return int reminders sent
     */
    public function sendFeeReminders(): int
    {
        $sent = 0;
        $today = CarbonImmutable::today();

        foreach (AssociationSetting::current()->fee_reminder_days as $days) {
            $instalments = FeeInstalment::query()->with('assignment.payer')
                ->whereIn('status', [InstalmentStatus::Open, InstalmentStatus::Partial])
                ->whereDate('due_on', $today->addDays((int) $days)->toDateString())
                ->get();

            foreach ($instalments as $instalment) {
                if ($this->claim('fee_due', $instalment->id, (int) $days)) {
                    $this->mailer->send($instalment->assignment->payer->email, __('associations.mail.fee_subject'), [__('associations.mail.fee_line', [
                        'description' => $instalment->assignment->description,
                        'amount' => $instalment->outstanding(),
                        'date' => $instalment->due_on->toDateString(),
                    ])]);
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /**
     * @return int reminders sent
     */
    public function sendRenewalReminders(): int
    {
        $sent = 0;
        $today = CarbonImmutable::today();

        foreach (AssociationSetting::current()->renewal_reminder_days as $days) {
            $memberships = Membership::query()->with('member.contact')
                ->where('status', MembershipStatus::Active)
                ->whereDate('valid_to', $today->addDays((int) $days)->toDateString())
                ->get();

            foreach ($memberships as $membership) {
                if ($this->claim('renewal_due', $membership->id, (int) $days)) {
                    $this->mailer->send($this->recipient($membership->member), __('associations.mail.renewal_subject'), [__('associations.mail.renewal_line', [
                        'date' => $membership->valid_to->toDateString(),
                    ])]);
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /**
     * Alerts once when a certificate enters the alert period, and once more after it has expired.
     *
     * @return int reminders sent
     */
    public function sendCertificateAlerts(): int
    {
        $sent = 0;
        $alertDays = AssociationSetting::current()->certificate_alert_days;

        foreach ($this->certificates->expiring($alertDays)->get() as $certificate) {
            $sent += $this->alert($certificate, $alertDays, 'associations.mail.certificate_expiring_line');
        }

        foreach ($this->certificates->expired()->get() as $certificate) {
            $sent += $this->alert($certificate, 0, 'associations.mail.certificate_expired_line');
        }

        return $sent;
    }

    private function alert(MedicalCertificate $certificate, int $days, string $line): int
    {
        if (! $this->claim('certificate', $certificate->id, $days)) {
            return 0;
        }

        $this->mailer->send($this->recipient($certificate->member), __('associations.mail.certificate_subject'), [__($line, ['date' => $certificate->expires_on->toDateString()])]);

        return 1;
    }

    /**
     * Mail about a minor goes to the guardian.
     */
    private function recipient(Member $member): ?string
    {
        if ($member->isMinor()) {
            return $member->guardianships()->active()->orderByDesc('is_primary')->first()?->guardian?->email ?? $member->contact->email;
        }

        return $member->contact->email;
    }

    private function claim(string $kind, string $subjectId, int $daysBefore): bool
    {
        return DB::table('assoc_notices')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantContext->getTenantId(),
            'kind' => $kind,
            'subject_id' => $subjectId,
            'days_before' => $daysBefore,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }
}
