<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * One-time password sent to a partner work e-mail address. Only a keyed hash of the code is stored.
 *
 * @property string $convention_id
 * @property string $email
 * @property string $otp_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $verified_at
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property string|null $deleted_by
 */
final class ConventionOtpChallenge extends BaseEnterpriseModel
{
    protected $table = 'sales_convention_otp_challenges';

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    public function isOpen(): bool
    {
        return $this->verified_at === null && $this->expires_at->isFuture();
    }

    public function matches(string $code): bool
    {
        return hash_equals($this->otp_hash, self::hashCode($code));
    }
}
