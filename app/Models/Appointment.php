<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'appointments';

    protected $fillable = [
        'tenant_id',
        'appointment_number',
        'status_id',
        'contact_id',
        'ticket_id',
        'service_report_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'all_day',
        'address',
        'latitude',
        'longitude',
        'estimated_travel_minutes',
        'estimated_duration_minutes',
        'technician_notes',
        'customer_notes',
        'is_portal_visible',
        'reminder_sent_at',
        'confirmed_at',
        'cancelled_at',
        'cancellation_reason',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'estimated_travel_minutes' => 'integer',
            'estimated_duration_minutes' => 'integer',
            'is_portal_visible' => 'boolean',
            'reminder_sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LookupItem, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(LookupItem::class, 'status_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    /**
     * @return BelongsTo<ServiceReport, $this>
     */
    public function serviceReport(): BelongsTo
    {
        return $this->belongsTo(ServiceReport::class, 'service_report_id');
    }

    /**
     * @return HasMany<AppointmentAttendee, $this>
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(AppointmentAttendee::class, 'appointment_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'appointment_attendees', 'appointment_id', 'user_id')
            ->withPivot(['role', 'is_organizer', 'status'])
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Shared attachments polymorphic relationship.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Scope query to appointments overlapping a date/time range.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeBetween(Builder $query, CarbonInterface|string $start, CarbonInterface|string $end): Builder
    {
        return $query->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);
    }

    /**
     * Scope query to appointments where a specific technician is attending.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForTechnician(Builder $query, string $userId): Builder
    {
        return $query->whereHas('attendees', function (Builder $q) use ($userId): void {
            $q->where('user_id', $userId);
        });
    }

    /**
     * Scope query to appointments visible on customer portal.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePortalVisible(Builder $query): Builder
    {
        return $query->where('is_portal_visible', true);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null || ($this->status && $this->status->code === 'cancelled');
    }

    public function isCompleted(): bool
    {
        return $this->status && $this->status->code === 'completed';
    }
}
