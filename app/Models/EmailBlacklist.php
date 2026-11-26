<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EmailBlacklistFactory;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-tenant or global email suppression list (unsubscribes, hard bounces, spam complaints, manual blocks).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $email
 * @property string $reason
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EmailBlacklist extends Model
{
    /** @use HasFactory<EmailBlacklistFactory> */
    use AllowsGlobalRecords, BelongsToTenant, HasFactory, HasUuids;

    public const REASON_UNSUBSCRIBED = 'unsubscribed';

    public const REASON_HARD_BOUNCE = 'hard_bounce';

    public const REASON_SPAM_COMPLAINT = 'spam_complaint';

    public const REASON_MANUAL = 'manual';

    /**
     * @var list<string>
     */
    public const REASONS = [
        self::REASON_UNSUBSCRIBED,
        self::REASON_HARD_BOUNCE,
        self::REASON_SPAM_COMPLAINT,
        self::REASON_MANUAL,
    ];

    protected $table = 'email_blacklist';

    protected $attributes = [
        'reason' => self::REASON_MANUAL,
    ];

    protected $fillable = [
        'tenant_id',
        'email',
        'reason',
        'notes',
    ];

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => strtolower(trim($value)),
        );
    }

    public static function isBlacklisted(string $email, ?string $tenantId = null): bool
    {
        $normalized = strtolower(trim($email));

        if ($normalized === '') {
            return false;
        }

        return self::query()
            ->withoutGlobalScopes()
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->where(static function ($query) use ($tenantId): void {
                $query->whereNull('tenant_id');
                if ($tenantId !== null && $tenantId !== '') {
                    $query->orWhere('tenant_id', $tenantId);
                }
            })
            ->exists();
    }
}
