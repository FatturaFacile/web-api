<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Whether a contact opted out of one channel. It narrows the CRM consent, it never widens it: a marketing message
 * needs a CRM opt-in and no opt-out here.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property string $channel
 * @property bool $is_opted_out
 * @property string|null $reason
 * @property Carbon|null $changed_at
 * @property string|null $changed_by
 */
final class ContactChannelPreference extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Channels = ['email', 'whatsapp', 'telegram', 'sms'];

    protected $table = 'comm_contact_preferences';

    protected function casts(): array
    {
        return ['is_opted_out' => 'boolean', 'changed_at' => 'datetime'];
    }
}
