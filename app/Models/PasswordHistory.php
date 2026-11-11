<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A hash of a password the user used before. It belongs to the user, not to a tenant: the password is one for every
 * tenant of the user.
 *
 * @property string $id
 * @property string $user_id
 * @property string $password_hash
 */
class PasswordHistory extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['password_hash'];
}
