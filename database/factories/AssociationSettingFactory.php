<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\AssociationSetting;

/**
 * @extends Factory<AssociationSetting>
 */
final class AssociationSettingFactory extends Factory
{
    protected $model = AssociationSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'card_prefix' => 'TES-',
            'card_padding' => 6,
            'receipt_prefix' => 'REC-',
            'default_receipt_kind' => 'simple',
            'fee_reminder_days' => AssociationSetting::DefaultFeeReminderDays,
            'renewal_reminder_days' => AssociationSetting::DefaultRenewalReminderDays,
            'certificate_alert_days' => 30,
            'presence_max_hours' => 12,
            'ledger_enabled' => true,
            'shift_alert_days' => 3,
            'volunteer_expiry_alert_days' => 30,
            'donation_receipt_prefix' => 'DON-',
            'lex398_enabled' => false,
        ];
    }
}
