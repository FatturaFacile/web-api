<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\ShiftAvailability;
use Modules\Associations\Models\Volunteer;
use Modules\Associations\Models\VolunteerTraining;
use Ramsey\Uuid\Uuid;

/**
 * The scheduled alerts of the volunteers: shifts that miss people or wait for a substitute (to the volunteers who said
 * they are available), trainings and insurance coverage about to expire. The sent notices table makes every alert go
 * out once, whatever the number of runs.
 */
final class VolunteerAlertService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ShiftService $shifts,
        private readonly AssociationMailer $mailer,
    ) {}

    /**
     * @return array{shifts: int, trainings: int, insurance: int}
     */
    public function send(): array
    {
        $settings = AssociationSetting::current();
        $today = CarbonImmutable::today();
        $sent = ['shifts' => 0, 'trainings' => 0, 'insurance' => 0];

        foreach ($this->shifts->needingAttention(CarbonImmutable::now(), $today->addDays((int) $settings->shift_alert_days)->endOfDay()) as $shift) {
            $available = ShiftAvailability::query()->with('volunteer.member.contact')->where('shift_id', $shift->id)->where('is_available', true)->get();

            foreach ($available as $availability) {
                if ($this->claim('shift_coverage', $shift->id.':'.$availability->volunteer_id)) {
                    $this->mailer->send($availability->volunteer?->member?->contact?->email, (string) __('association_governance.mail.shift_subject'), [(string) __('association_governance.mail.shift_line', [
                        'title' => $shift->title,
                        'date' => $shift->starts_at->format('d/m/Y H:i'),
                    ])]);
                    $sent['shifts']++;
                }
            }
        }

        $limit = $today->addDays((int) $settings->volunteer_expiry_alert_days)->toDateString();

        foreach (VolunteerTraining::query()->with('volunteer.member.contact')->whereNotNull('expires_on')->whereDate('expires_on', '<=', $limit)->whereDate('expires_on', '>=', $today->toDateString())->get() as $training) {
            if ($this->claim('training_expiry', $training->id.':'.$training->expires_on->toDateString())) {
                $this->mailer->send($training->volunteer?->member?->contact?->email, (string) __('association_governance.mail.training_subject'), [(string) __('association_governance.mail.training_line', [
                    'title' => $training->title,
                    'date' => $training->expires_on->toDateString(),
                ])]);
                $sent['trainings']++;
            }
        }

        foreach (Volunteer::query()->with('member.contact')->whereNotNull('insurance_expires_on')->whereDate('insurance_expires_on', '<=', $limit)->whereDate('insurance_expires_on', '>=', $today->toDateString())->get() as $volunteer) {
            if ($this->claim('insurance_expiry', $volunteer->id.':'.$volunteer->insurance_expires_on->toDateString())) {
                $this->mailer->send($volunteer->member?->contact?->email, (string) __('association_governance.mail.insurance_subject'), [(string) __('association_governance.mail.insurance_line', [
                    'date' => $volunteer->insurance_expires_on->toDateString(),
                ])]);
                $sent['insurance']++;
            }
        }

        return $sent;
    }

    private function claim(string $kind, string $key): bool
    {
        return DB::table('assoc_notices')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantContext->getTenantId(),
            'kind' => $kind,
            'subject_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'association-alert:'.$key)->toString(),
            'days_before' => 0,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }
}
