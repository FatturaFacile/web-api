<?php

declare(strict_types=1);

namespace Modules\Visitors\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An expected or walk-in visit: expected, then checked in with a badge, then checked out. A cancelled visit never
 * started.
 */
final class VisitorVisit extends VisitorsRecord
{
    public const string Expected = 'expected';

    public const string CheckedIn = 'checked_in';

    public const string CheckedOut = 'checked_out';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Expected, self::CheckedIn, self::CheckedOut, self::Cancelled];

    protected $table = 'visitor_visits';

    protected function casts(): array
    {
        return [
            'expected_at' => 'datetime',
            'expected_until' => 'datetime',
            'purpose' => 'encrypted',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'host_notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Visitor, $this>
     */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'visitor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    /**
     * @return BelongsTo<VisitorKiosk, $this>
     */
    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(VisitorKiosk::class, 'kiosk_id');
    }
}
