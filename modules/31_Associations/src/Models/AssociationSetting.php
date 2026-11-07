<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * Settings of the module for one company, created with their defaults the first time they are needed.
 */
final class AssociationSetting extends AssociationRecord
{
    public const array DefaultFeeReminderDays = [30, 15, 7];

    public const array DefaultRenewalReminderDays = [30, 7];

    protected $table = 'assoc_settings';

    protected $attributes = [
        'card_prefix' => '',
        'card_padding' => 6,
        'receipt_prefix' => 'REC-',
        'default_receipt_kind' => 'simple',
        'certificate_alert_days' => 30,
        'presence_max_hours' => 12,
        'ledger_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'fee_reminder_days' => 'array',
            'renewal_reminder_days' => 'array',
            'certificate_alert_days' => 'integer',
            'presence_max_hours' => 'integer',
            'card_padding' => 'integer',
            'ledger_enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'fee_reminder_days' => self::DefaultFeeReminderDays,
            'renewal_reminder_days' => self::DefaultRenewalReminderDays,
        ]);
    }
}
