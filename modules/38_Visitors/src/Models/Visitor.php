<?php

declare(strict_types=1);

namespace Modules\Visitors\Models;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person who visits the company, optionally linked to a contact of the CRM. Every personal field is encrypted at rest
 * and the record is erased at the end of the retention period.
 *
 * @property string $first_name
 * @property string $last_name
 */
final class Visitor extends VisitorsRecord
{
    protected $table = 'visitors';

    protected $hidden = ['document_number'];

    protected function casts(): array
    {
        return [
            'first_name' => 'encrypted',
            'last_name' => 'encrypted',
            'company_name' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'document_number' => 'encrypted',
            'last_visit_at' => 'datetime',
        ];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * The name a kiosk may show on its screen: first name and initial of the last name.
     */
    public function kioskName(): string
    {
        return trim($this->first_name.' '.mb_substr((string) $this->last_name, 0, 1).'.');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return HasMany<VisitorVisit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(VisitorVisit::class, 'visitor_id')->orderByDesc('expected_at');
    }
}
